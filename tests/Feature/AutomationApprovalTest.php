<?php

namespace Tests\Feature;

use App\Livewire\Automations\Board;
use App\Models\AutomationApproval;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Notifications\GovernanceNotification;
use App\Services\ActivityFeed;
use App\Services\AutomationCatalog;
use App\Services\Automations;
use App\Services\ProjectPeople;
use App\Services\ProjectWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P9c: approval steps and branches. A run pauses for an answer from the people asked; the
 * first answer closes it for all; steps after it run only if approved, only if not, or either way;
 * unanswered requests expire as not approved; a step can carry its own condition, read when it
 * runs.
 */
class AutomationApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
    }

    /** @return array{boss: User, pm: User, ed: User, kiln: Project, task: ProjectTask} */
    private function business(): array
    {
        $boss = User::factory()->withPersonalTeam()->create(['name' => 'Bongi Boss']);
        $team = $boss->currentTeam;
        $make = function (string $name) use ($team) {
            $u = User::factory()->create(['name' => $name, 'current_team_id' => $team->id]);
            $team->users()->attach($u, ['role' => 'editor']);

            return $u;
        };
        $pm = $make('Palesa PM');
        $ed = $make('Ed Editor');
        $kiln = Project::create(['team_id' => $team->id, 'owner_id' => $pm->id, 'name' => 'Kiln reline', 'status' => 'active']);
        $task = $kiln->tasks()->create(['title' => 'Reline', 'status' => 'in_progress', 'priority' => 'high', 'assignee_id' => $ed->id]);

        return ['boss' => $boss, 'pm' => $pm, 'ed' => $ed, 'kiln' => $kiln->fresh(), 'task' => $task];
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function rule(User $by, Project $p, string $trigger, string $param, array $actions, string $name = 'Sign-off'): AutomationRule
    {
        $this->actingAs($by);
        $svc = app(Automations::class);
        $rule = $svc->save(null, $by->currentTeam, $by, ['name' => $name, 'project_id' => (string) $p->id, 'trigger' => $trigger, 'param' => $param, 'conditions' => [], 'actions' => $actions]);
        $svc->setActive($rule, $by, true);

        return $rule->fresh();
    }

    private function refuses(callable $call, string $key, string $contains): void
    {
        try {
            $call();
            $this->fail('expected a refusal on '.$key);
        } catch (ValidationException $e) {
            $this->assertStringContainsString($contains, $e->errors()[$key][0]);
        }
    }

    public function test_a_run_waits_for_an_answer_and_follows_the_branch_it_chooses(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'ed' => $ed, 'kiln' => $kiln, 'task' => $task] = $this->business();
        Notification::fake();
        $rule = $this->rule($pm, $kiln, 'task_status', 'review', [
            ['type' => 'approval', 'approver' => 'project_owner', 'question' => 'Is the lining good enough to sign off?', 'expire_days' => 3],
            ['type' => 'notify', 'to' => ['subject_person'], 'note' => 'Signed off.', 'when' => ['approval' => 'approved']],
            ['type' => 'create_tasks', 'titles' => 'Redo the lining', 'due_days' => '1', 'assign' => 'subject_person', 'priority' => 'high', 'when' => ['approval' => 'rejected']],
            ['type' => 'add_label', 'label' => 'Reviewed', 'when' => ['approval' => 'any']],
        ]);
        $this->assertSame('When a task moves to In review in Kiln reline, then ask the project owner to approve (“Is the lining good enough to sign off?”), waiting up to 3 days, then, if approved, notify the task\'s assignee with the note “Signed off.”, then, if not approved, add the task “Redo the lining” for the task\'s assignee, due 1 day later, high priority, then, either way, label the task “Reviewed”.', AutomationCatalog::describe($rule, 'Kiln reline'));

        $this->actingAs($ed);
        app(ProjectWork::class)->updateTask($task, ['status' => 'review'], $ed);
        $run = AutomationRun::query()->sole();
        $this->assertSame(['awaiting', 'Asked Palesa PM to approve, by 7 Nov'], [$run->status, $run->steps[0]['summary']]);
        Notification::assertSentTo($pm, GovernanceNotification::class, fn ($n) => $n->type === 'automation_approval' && str_contains($n->message, 'Is the lining good enough to sign off?'));
        $this->assertSame([], $task->fresh()->labels()->pluck('name')->all(), 'nothing after the approval runs before the answer');

        $request = AutomationApproval::query()->sole();
        $svc = app(Automations::class);
        $this->refuses(fn () => $svc->decide($request, $boss, true, null), 'approval', 'You were not asked');
        $this->refuses(fn () => $svc->decide($request, $pm, false, '  '), 'approval', 'Say why');

        $this->actingAs($pm);
        Livewire::actingAs($pm)->test(Board::class)->call('showApprovals')->assertSee('Is the lining good enough to sign off?')
            ->assertSee('Notify the task\'s assignee with the note “Signed off.”; label the task “Reviewed”')
            ->call('decide', $request->id, true)->assertHasNoErrors()->assertSee('Nothing is waiting for your approval.');

        $run->refresh();
        $this->assertSame('done', $run->status);
        $this->assertSame(['Asked Palesa PM to approve, by 7 Nov; approved by Palesa PM', 'Notified Ed Editor', 'Skipped: it was approved', 'Labelled it “Reviewed”'], array_column($run->steps, 'summary'));
        $this->assertSame(0, ProjectTask::query()->where('title', 'Redo the lining')->count());
        $this->assertSame(['Reviewed'], $task->fresh()->labels()->pluck('name')->all());
        $this->assertContains('approved a step of the rule “Sign-off”', array_column(ActivityFeed::forProject($kiln, 20), 'text'));
        $this->assertSame('Palesa PM', collect(ActivityFeed::forProject($kiln, 20))->firstWhere('text', 'approved a step of the rule “Sign-off”')['who']);
        $this->refuses(fn () => $svc->decide($request->fresh(), $pm, true, null), 'approval', 'already been answered');
    }

    public function test_an_unanswered_request_expires_as_not_approved_for_everyone_asked(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'ed' => $ed, 'kiln' => $kiln, 'task' => $task] = $this->business();
        $this->actingAs($boss);
        $mo = User::factory()->create(['name' => 'Mo Manager', 'current_team_id' => $boss->currentTeam->id]);
        $boss->currentTeam->users()->attach($mo, ['role' => 'editor']);
        app(ProjectPeople::class)->add($kiln, $boss, $mo->id, 'manager');
        app(ProjectPeople::class)->add($kiln, $boss, $ed->id, 'manager');
        $this->rule($pm, $kiln, 'task_status', 'review', [
            ['type' => 'approval', 'approver' => 'project_managers', 'question' => 'Sign it off?', 'expire_days' => 2],
            ['type' => 'create_tasks', 'titles' => 'Chase the sign-off', 'due_days' => '', 'assign' => 'project_owner', 'priority' => 'high', 'when' => ['approval' => 'rejected']],
        ]);

        $this->actingAs($pm);
        app(ProjectWork::class)->updateTask($task, ['status' => 'review'], $pm);
        auth()->logout();
        $this->assertEqualsCanonicalizing([$mo->id, $ed->id], AutomationApproval::query()->pluck('user_id')->all());

        Carbon::setTestNow('2026-11-05 09:00:00');
        app(Automations::class)->sweep();
        $this->assertSame(2, AutomationApproval::query()->where('status', 'pending')->count(), 'not before it expires');
        Carbon::setTestNow('2026-11-06 09:30:00');
        app(Automations::class)->sweep();
        $this->assertSame(['expired', 'expired'], AutomationApproval::query()->pluck('status')->all());
        $run = AutomationRun::query()->sole();
        $this->assertSame(['done', 'Asked Ed Editor and Mo Manager to approve, by 6 Nov; no answer by 6 Nov, so treated as not approved'], [$run->status, $run->steps[0]['summary']]);
        $this->assertSame($pm->id, ProjectTask::query()->where('title', 'Chase the sign-off')->value('assignee_id'));
    }

    public function test_a_step_condition_is_read_when_the_step_runs_and_nobody_to_ask_is_a_failure(): void
    {
        ['pm' => $pm, 'ed' => $ed, 'kiln' => $kiln, 'task' => $task] = $this->business();
        $this->rule($pm, $kiln, 'task_status', 'review', [
            ['type' => 'add_label', 'label' => 'Checked'],
            ['type' => 'set_priority', 'priority' => 'urgent', 'when' => ['field' => 'priority', 'values' => ['low']]],
        ], 'Guarded');
        $this->rule($pm, $kiln, 'task_status', 'done', [
            ['type' => 'approval', 'approver' => 'project_managers', 'question' => 'Close it?', 'expire_days' => 2],
            ['type' => 'add_label', 'label' => 'Closed'],
        ], 'Nobody');

        $this->actingAs($ed);
        app(ProjectWork::class)->updateTask($task, ['status' => 'review'], $ed);
        $guarded = AutomationRun::query()->whereHas('rule', fn ($q) => $q->where('name', 'Guarded'))->sole();
        $this->assertSame(['done', 'Skipped: its priority is High'], [$guarded->status, $guarded->steps[1]['summary']]);
        $this->assertSame('high', $task->fresh()->priority);

        app(ProjectWork::class)->updateTask($task->fresh(), ['status' => 'done'], $ed);
        $nobody = AutomationRun::query()->whereHas('rule', fn ($q) => $q->where('name', 'Nobody'))->sole();
        $this->assertSame(['failed', 'Nobody could answer the approval: a project manager is not set or cannot see the project.'], [$nobody->status, $nobody->error]);
        $this->assertNotContains('Closed', $task->fresh()->labels()->pluck('name')->all());
    }

    public function test_approval_rules_are_checked_before_they_are_saved(): void
    {
        ['pm' => $pm, 'kiln' => $kiln] = $this->business();
        $base = ['name' => 'R', 'project_id' => (string) $kiln->id, 'trigger' => 'task_created', 'param' => '', 'conditions' => []];
        $ask = ['type' => 'approval', 'approver' => 'project_owner', 'question' => 'OK?', 'expire_days' => 7];
        $note = ['type' => 'notify', 'to' => ['project_owner'], 'note' => ''];
        $team = $pm->currentTeam;

        $this->refuses(fn () => AutomationCatalog::clean($base + ['actions' => [$ask, $ask, $note]], $team, $pm), 'actions', 'asks for approval once');
        $this->refuses(fn () => AutomationCatalog::clean($base + ['actions' => [$note, $ask]], $team, $pm), 'actions', 'An approval needs a step after it');
        $this->refuses(fn () => AutomationCatalog::clean($base + ['actions' => [['question' => ''] + $ask, $note]], $team, $pm), 'actions', 'what you are asking them to approve');
        $this->refuses(fn () => AutomationCatalog::clean($base + ['actions' => [['expire_days' => 60] + $ask, $note]], $team, $pm), 'actions', '1 to 30 days');
        $this->refuses(fn () => AutomationCatalog::clean($base + ['actions' => [['when' => ['field' => 'priority', 'values' => []]] + $note]], $team, $pm), 'actions', 'at least one value for its condition');

        $clean = AutomationCatalog::clean($base + ['actions' => [$note, $ask, $note]], $team, $pm);
        $this->assertSame([null, null, 'approved'], array_map(fn ($a) => $a['when']['approval'], $clean['actions']), 'a branch only after the approval, and only if approved by default');
    }
}
