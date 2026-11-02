<?php

namespace Tests\Feature;

use App\Livewire\Automations\Board;
use App\Models\Activity as ActivityLine;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Notifications\GovernanceNotification;
use App\Services\Activity;
use App\Services\ActivityFeed;
use App\Services\AutomationCatalog;
use App\Services\Automations;
use App\Services\CostLedger;
use App\Services\Governance;
use App\Services\ProjectPeople;
use App\Services\ProjectWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P9a: automation rules. Triggers from the activity log, conditions, ordered actions run as
 * the rule's maker; idempotent runs; no chains; failures recorded per step and retried safely;
 * notices only to people who can see the project and never to the person who set the rule off;
 * a sentence and a dry run before switching a rule on.
 */
class AutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
    }

    /** @return array{boss: User, pm: User, ed: User, kiln: Project, belt: Project, task: ProjectTask} */
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
        $belt = Project::create(['team_id' => $team->id, 'owner_id' => $boss->id, 'name' => 'Belt swap', 'status' => 'active']);
        $task = $kiln->tasks()->create(['title' => 'Reline', 'status' => 'in_progress', 'priority' => 'high', 'assignee_id' => $ed->id]);

        return ['boss' => $boss, 'pm' => $pm, 'ed' => $ed, 'kiln' => $kiln->fresh(), 'belt' => $belt->fresh(), 'task' => $task];
    }

    /** @param  array<string, mixed>  $in */
    private function rule(User $by, array $in, bool $on = true): AutomationRule
    {
        $this->actingAs($by);
        $svc = app(Automations::class);
        $rule = $svc->save(null, $by->currentTeam, $by, $in + ['name' => 'Rule', 'param' => '', 'conditions' => []]);
        if ($on) {
            $svc->setActive($rule, $by, true);
        }

        return $rule->fresh();
    }

    private function refuses(callable $call, string $key, ?string $contains = null): void
    {
        try {
            $call();
            $this->fail('expected a refusal on '.$key);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
            if ($contains !== null) {
                $this->assertStringContainsString($contains, $e->errors()[$key][0]);
            }
        }
    }

    public function test_a_rule_runs_its_steps_once_as_its_maker_and_says_so(): void
    {
        ['pm' => $pm, 'ed' => $ed, 'kiln' => $kiln, 'task' => $task] = $this->business();
        Notification::fake();
        $rule = $this->rule($pm, ['name' => 'Close-out', 'project_id' => (string) $kiln->id, 'trigger' => 'task_status', 'param' => 'done', 'actions' => [
            ['type' => 'notify', 'to' => ['project_owner', 'subject_person'], 'note' => 'Check the snag list.'],
            ['type' => 'create_tasks', 'titles' => "Write the handover note\nPhotograph the lining", 'due_days' => '2', 'assign' => 'project_owner', 'priority' => 'high'],
        ]]);
        $this->assertSame('When a task moves to Done in Kiln reline, then notify the project owner and the task\'s assignee with the note “Check the snag list.”, then add 2 tasks (“Write the handover note”, “Photograph the lining”) for the project owner, due 2 days later, high priority.', AutomationCatalog::describe($rule, 'Kiln reline'));

        $this->actingAs($ed);
        app(ProjectWork::class)->updateTask($task, ['status' => 'done'], $ed);

        $run = AutomationRun::query()->sole();
        $this->assertSame(['done', 'Task “Reline” moved to Done', ['Notified Palesa PM', 'Added 2 tasks for Palesa PM']], [$run->status, $run->what, array_column($run->steps, 'summary')]);
        Notification::assertSentTo($pm, GovernanceNotification::class, fn ($n) => $n->type === 'automation' && $n->title === 'Rule: Close-out' && str_contains($n->message, 'Task “Reline” moved to Done on “Kiln reline”. Check the snag list.'));
        Notification::assertNotSentTo($ed, GovernanceNotification::class, 'the person who set it off is not told about their own action');
        $made = ProjectTask::query()->where('title', 'Write the handover note')->sole();
        $this->assertSame([$pm->id, 'high', '2026-11-06'], [$made->assignee_id, $made->priority, $made->due_date->toDateString()]);

        $line = ActivityLine::query()->where('action', 'task.created')->where('subject_id', $made->id)->sole();
        $this->assertSame([null, $rule->id], [$line->user_id, $line->changes['rule_id']]);
        $this->assertContains('The rule “Close-out” added “Write the handover note”', array_column(ActivityFeed::forProject($kiln, 20), 'text'));

        app(Automations::class)->handle(ActivityLine::query()->where('action', 'task.updated')->sole());
        $this->assertSame(1, AutomationRun::query()->count(), 'the same event never runs a rule twice');
    }

    public function test_conditions_scope_and_switches_decide_whether_a_rule_runs(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'ed' => $ed, 'kiln' => $kiln, 'belt' => $belt] = $this->business();
        $notify = [['type' => 'notify', 'to' => ['project_owner'], 'note' => '']];
        $urgentOnly = $this->rule($pm, ['project_id' => (string) $kiln->id, 'trigger' => 'task_created', 'conditions' => [['field' => 'priority', 'values' => ['urgent']]], 'actions' => $notify]);
        $off = $this->rule($pm, ['project_id' => (string) $kiln->id, 'trigger' => 'task_created', 'actions' => $notify], false);
        $everywhere = $this->rule($boss, ['project_id' => '', 'trigger' => 'task_created', 'actions' => $notify]);

        $this->actingAs($ed);
        $kiln->tasks()->create(['title' => 'Quiet', 'status' => 'todo', 'priority' => 'low']);
        $t = $kiln->tasks()->create(['title' => 'Loud', 'status' => 'todo', 'priority' => 'urgent']);
        foreach ([$kiln->tasks()->where('title', 'Quiet')->sole(), $t] as $task) {
            Activity::record('task.created', $task, [], $kiln);
        }
        $belt->tasks()->create(['title' => 'Elsewhere', 'status' => 'todo', 'priority' => 'urgent']);
        Activity::record('task.created', $belt->tasks()->sole(), [], $belt);

        $this->assertSame(1, AutomationRun::query()->where('rule_id', $urgentOnly->id)->count(), 'only the urgent task in its own project');
        $this->assertSame(0, AutomationRun::query()->where('rule_id', $off->id)->count(), 'a rule that is off never runs');
        $this->assertSame(3, AutomationRun::query()->where('rule_id', $everywhere->id)->count(), 'a business-wide rule runs in every project');
    }

    public function test_state_rules_run_once_per_record_and_never_set_off_other_rules(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'ed' => $ed, 'kiln' => $kiln] = $this->business();
        $this->actingAs($boss);
        app(ProjectPeople::class)->add($kiln, $boss, $ed->id, 'manager');
        $this->rule($pm, ['project_id' => (string) $kiln->id, 'trigger' => 'risk_rated', 'param' => 'high', 'actions' => [['type' => 'escalate'], ['type' => 'create_tasks', 'titles' => 'Review the risk', 'due_days' => '', 'assign' => 'none', 'priority' => 'high']]]);
        $chained = $this->rule($pm, ['project_id' => (string) $kiln->id, 'trigger' => 'task_created', 'actions' => [['type' => 'create_tasks', 'titles' => 'Never', 'due_days' => '', 'assign' => 'none', 'priority' => 'low']]]);

        $this->actingAs($ed);
        $gov = app(Governance::class);
        $risk = $gov->addRisk($kiln, $ed, ['title' => 'Bricks late', 'probability' => 4, 'impact' => 4]);
        $gov->updateRisk($risk->fresh(), $ed, ['impact' => 5]);

        $this->assertSame(1, AutomationRun::query()->where('key', 'risk:'.$risk->id)->count(), 'once per risk, however often it changes');
        $this->assertNotNull($risk->fresh()->escalated_at);
        $this->assertSame(1, ProjectTask::query()->where('title', 'Review the risk')->count());
        $this->assertSame(0, AutomationRun::query()->where('rule_id', $chained->id)->count(), 'what a rule does never sets off another rule');
        $this->assertSame(0, ProjectTask::query()->where('title', 'Never')->count());
    }

    public function test_onboarding_and_budget_rules_act_on_the_right_people_and_shares(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'ed' => $ed, 'kiln' => $kiln] = $this->business();
        Notification::fake();
        $template = AutomationCatalog::TEMPLATES['onboarding'];
        $this->rule($pm, ['name' => $template['name'], 'project_id' => (string) $kiln->id, 'trigger' => $template['trigger'], 'actions' => $template['actions']]);
        $this->rule($pm, ['project_id' => (string) $kiln->id, 'trigger' => 'budget_threshold', 'param' => '90', 'actions' => [['type' => 'notify', 'to' => ['project_owner', 'business_owner'], 'note' => '']]]);

        $this->actingAs($pm);
        app(ProjectPeople::class)->add($kiln, $pm, $ed->id, 'member');
        $onboarding = ProjectTask::query()->where('assignee_id', $ed->id)->where('title', '!=', 'Reline')->get();
        $this->assertSame(['Read the project brief and plan', 'Meet the project owner', 'Check you can open the project\'s documents'], $onboarding->pluck('title')->all());
        $this->assertSame('2026-11-07', $onboarding->first()->due_date->toDateString());

        $ledger = app(CostLedger::class);
        $line = $ledger->addLine($kiln, $pm, 'materials', 'Bricks', 1_000_000, null);
        $ledger->addCost($kiln, $pm, ['budget_line_id' => $line->id, 'kind' => 'commitment', 'description' => 'Order', 'amount_cents' => 500_000, 'dated' => '2026-11-03']);
        $this->assertSame(0, AutomationRun::query()->where('key', 'budget:90')->count());
        $ledger->addCost($kiln, $pm, ['budget_line_id' => $line->id, 'kind' => 'actual', 'description' => 'Invoice', 'amount_cents' => 410_000, 'dated' => '2026-11-03']);
        $run = AutomationRun::query()->where('key', 'budget:90')->sole();
        $this->assertSame('Spending and commitments have reached 91% of the budget', $run->what);
        Notification::assertSentTo($boss, GovernanceNotification::class, fn ($n) => str_contains($n->message, '91% of the budget') && ! str_contains($n->message, 'R '));
        $ledger->addCost($kiln, $pm, ['budget_line_id' => $line->id, 'kind' => 'actual', 'description' => 'More', 'amount_cents' => 100_000, 'dated' => '2026-11-03']);
        $this->assertSame(1, AutomationRun::query()->where('key', 'budget:90')->count(), 'once per share of the budget');
    }

    public function test_a_failed_step_is_recorded_and_a_retry_repeats_only_what_did_not_finish(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'ed' => $ed, 'kiln' => $kiln, 'task' => $task] = $this->business();
        Notification::fake();
        $this->actingAs($boss);
        app(ProjectPeople::class)->add($kiln, $boss, $ed->id, 'manager');
        $rule = $this->rule($ed, ['project_id' => (string) $kiln->id, 'trigger' => 'task_status', 'param' => 'review', 'actions' => [
            ['type' => 'notify', 'to' => ['project_owner'], 'note' => ''],
            ['type' => 'add_label', 'label' => 'Needs sign-off'],
        ]]);
        $boss->currentTeam->users()->updateExistingPivot($ed->id, ['role' => 'viewer']);

        $this->actingAs($pm);
        app(ProjectWork::class)->updateTask($task, ['status' => 'review'], $pm);
        $run = AutomationRun::query()->sole();
        $this->assertSame(['failed', [true, false]], [$run->status, array_column($run->steps, 'ok')]);
        $this->assertSame('Ed Editor, who made this rule, can no longer change this project.', $run->error);
        Notification::assertNothingSent();

        $member = User::factory()->create(['current_team_id' => $boss->currentTeam->id]);
        $boss->currentTeam->users()->attach($member, ['role' => 'editor']);
        $this->refuses(fn () => app(Automations::class)->retry($run, $member), 'run', 'Only someone who manages this rule');
        $boss->currentTeam->users()->updateExistingPivot($ed->id, ['role' => 'editor']);
        $this->actingAs($ed);
        Livewire::actingAs($ed)->test(Board::class, ['ruleId' => (string) $rule->id])->set('ruleId', (string) $rule->id)->call('retry', $run->id)->assertHasNoErrors();
        $run->refresh();
        $this->assertSame(['done', 2, [true, true]], [$run->status, $run->attempts, array_column($run->steps, 'ok')]);
        $this->assertSame(['Needs sign-off'], $task->fresh()->labels()->pluck('name')->all());
        Notification::assertNothingSent();
        $this->assertSame(1, ActivityLine::query()->where('action', 'label.added')->count());
    }

    public function test_who_may_make_rules_and_what_a_rule_must_say(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'ed' => $ed, 'kiln' => $kiln] = $this->business();
        $team = $boss->currentTeam;
        $ok = ['name' => 'R', 'project_id' => (string) $kiln->id, 'trigger' => 'task_created', 'param' => '', 'conditions' => [], 'actions' => [['type' => 'notify', 'to' => ['project_owner'], 'note' => '']]];

        $this->refuses(fn () => AutomationCatalog::clean($ok, $team, $ed), 'project_id', 'Choose a project you manage.');
        $this->refuses(fn () => AutomationCatalog::clean(['project_id' => ''] + $ok, $team, $pm), 'project_id', 'Only the business owner and admins');
        $this->refuses(fn () => AutomationCatalog::clean(['name' => ''] + $ok, $team, $pm), 'name');
        $this->refuses(fn () => AutomationCatalog::clean(['trigger' => 'task_status', 'param' => 'nope'] + $ok, $team, $pm), 'trigger');
        $this->refuses(fn () => AutomationCatalog::clean(['actions' => []] + $ok, $team, $pm), 'actions');
        $this->refuses(fn () => AutomationCatalog::clean(['actions' => [['type' => 'set_priority', 'priority' => 'high']], 'trigger' => 'milestone_completed'] + $ok, $team, $pm), 'actions', 'Step 1');
        $this->refuses(fn () => AutomationCatalog::clean(['actions' => [['type' => 'notify', 'to' => [], 'note' => '']]] + $ok, $team, $pm), 'actions', 'choose who to notify');
        $this->refuses(fn () => AutomationCatalog::clean(['conditions' => [['field' => 'priority', 'values' => []]]] + $ok, $team, $pm), 'conditions');
        $this->refuses(fn () => AutomationCatalog::clean(['actions' => [['type' => 'create_tasks', 'titles' => '', 'due_days' => '', 'assign' => 'none', 'priority' => 'medium']]] + $ok, $team, $pm), 'actions');
        $this->assertSame(['task_created', null], [AutomationCatalog::clean(['project_id' => ''] + $ok, $team, $boss)['trigger'], AutomationCatalog::clean(['project_id' => ''] + $ok, $team, $boss)['project_id']]);
    }

    public function test_the_page_builds_from_a_template_and_previews_before_switching_on(): void
    {
        ['pm' => $pm, 'ed' => $ed, 'kiln' => $kiln, 'task' => $task] = $this->business();
        $this->actingAs($ed);
        app(ProjectWork::class)->updateTask($task, ['priority' => 'urgent'], $ed);
        $other = $kiln->tasks()->create(['title' => 'Strip', 'status' => 'todo', 'priority' => 'urgent']);
        app(ProjectWork::class)->addDependency($task->fresh(), $other, $ed);

        $page = Livewire::actingAs($pm)->test(Board::class)->assertSee('Templates')->call('startNew', 'blocked_task')
            ->assertSee('When a task starts waiting on unfinished work in Kiln reline, if its priority is High or Urgent, then make the task urgent and tell the project owner, then notify the task\'s assignee.')
            ->assertSee('1 time')->assertSee('Task “Reline” now waits on unfinished work');
        $this->assertSame(0, AutomationRun::query()->count(), 'a preview does nothing');

        $page->call('save')->assertHasNoErrors()->assertSee('Switch on')->assertSee('Switched off, so it has not run.');
        $rule = AutomationRule::query()->sole();
        $this->assertFalse($rule->active);
        $page->call('toggle', $rule->id)->assertSee('Switch off');
        $this->assertTrue($rule->fresh()->active);

        Livewire::actingAs($ed)->test(Board::class)->call('select', $rule->id)->assertSee($rule->name)->assertDontSee('Switch off');
        Livewire::actingAs($ed)->test(Board::class)->call('toggle', $rule->id)->assertForbidden();
        $this->actingAs($pm)->get(route('automations.index'))->assertOk()->assertSee('>Automations<', false);
    }
}
