<?php

namespace Tests\Feature;

use App\Livewire\Automations\Board;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Project;
use App\Models\ProjectReport;
use App\Models\ProjectTask;
use App\Models\User;
use App\Notifications\GovernanceNotification;
use App\Services\AutomationCatalog;
use App\Services\Automations;
use App\Services\Governance;
use App\Services\ProjectWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P9b: rules that act on dates (overdue, due soon, deadlines, reviews, a weekly day), run by
 * the hourly sweep in working hours and once per record and date; waits that carry on later and
 * stop when they no longer apply; and starting a progress review.
 */
class AutomationDatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00'); // a Wednesday, 11:00 in South Africa
    }

    /** @return array{boss: User, pm: User, ed: User, kiln: Project} */
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
        $kiln = Project::create(['team_id' => $team->id, 'owner_id' => $pm->id, 'name' => 'Kiln reline', 'status' => 'active', 'due_date' => '2026-11-15']);

        return ['boss' => $boss, 'pm' => $pm, 'ed' => $ed, 'kiln' => $kiln->fresh()];
    }

    /** @param  array<string, mixed>  $in */
    private function rule(User $by, Project $p, array $in): AutomationRule
    {
        $this->actingAs($by);
        $svc = app(Automations::class);
        $rule = $svc->save(null, $by->currentTeam, $by, $in + ['name' => 'Rule', 'project_id' => (string) $p->id, 'param' => '', 'conditions' => []]);
        $svc->setActive($rule, $by, true);
        auth()->logout(); // the sweep runs with nobody signed in

        return $rule->fresh();
    }

    private function sweep(string $at): array
    {
        Carbon::setTestNow($at);

        return app(Automations::class)->sweep();
    }

    public function test_overdue_work_is_acted_on_once_per_date_and_only_in_working_hours(): void
    {
        ['pm' => $pm, 'ed' => $ed, 'kiln' => $kiln] = $this->business();
        Notification::fake();
        $late = $kiln->tasks()->create(['title' => 'Order bricks', 'status' => 'todo', 'priority' => 'high', 'assignee_id' => $ed->id, 'due_date' => '2026-11-02']);
        $kiln->tasks()->create(['title' => 'Paint', 'status' => 'todo', 'priority' => 'low', 'due_date' => '2026-11-01']);
        $kiln->tasks()->create(['title' => 'Done late', 'status' => 'done', 'priority' => 'high', 'due_date' => '2026-11-01']);
        $kiln->tasks()->create(['title' => 'Later', 'status' => 'todo', 'priority' => 'high', 'due_date' => '2026-11-20']);
        $this->rule($pm, $kiln, ['trigger' => 'task_overdue', 'conditions' => [['field' => 'priority', 'values' => ['high', 'urgent']]], 'actions' => [['type' => 'notify', 'to' => ['subject_person', 'project_owner'], 'note' => '']]]);

        $this->assertSame(['fired' => 0, 'resumed' => 0], $this->sweep('2026-11-04 03:00:00'), 'nothing before 07:00 in South Africa');
        $this->assertSame(1, $this->sweep('2026-11-04 09:05:00')['fired']);
        $this->assertSame(0, $this->sweep('2026-11-04 10:05:00')['fired'], 'once per task and due date');
        $run = AutomationRun::query()->sole();
        $this->assertSame(['Task “Order bricks” is overdue (it was due 2 Nov)', 'Notified Ed Editor and Palesa PM'], [$run->what, $run->steps[0]['summary']]);
        Notification::assertSentTo([$ed, $pm], GovernanceNotification::class);

        $late->update(['due_date' => '2026-11-04']);
        $this->assertSame(0, $this->sweep('2026-11-04 11:05:00')['fired']);
        $this->assertSame(1, $this->sweep('2026-11-05 09:05:00')['fired'], 'a new date is a new deadline to miss');
    }

    public function test_due_soon_milestones_reviews_and_weekly_rules_find_what_is_due(): void
    {
        ['pm' => $pm, 'kiln' => $kiln] = $this->business();
        $kiln->tasks()->create(['title' => 'Due today', 'status' => 'todo', 'priority' => 'medium', 'due_date' => '2026-11-04']);
        $kiln->tasks()->create(['title' => 'Due Friday', 'status' => 'in_progress', 'priority' => 'medium', 'due_date' => '2026-11-06']);
        $kiln->tasks()->create(['title' => 'Due next week', 'status' => 'todo', 'priority' => 'medium', 'due_date' => '2026-11-11']);
        $kiln->milestones()->create(['title' => 'Kiln cold', 'status' => 'pending', 'due_date' => '2026-11-03']);
        $kiln->milestones()->create(['title' => 'Kiln hot', 'status' => 'pending', 'due_date' => '2026-11-05']);
        $this->actingAs($pm);
        $risk = app(Governance::class)->addRisk($kiln, $pm, ['title' => 'Bricks late', 'probability' => 3, 'impact' => 3, 'review_date' => '2026-11-04']);
        $notify = [['type' => 'notify', 'to' => ['project_owner'], 'note' => '']];

        $engine = app(Automations::class);
        $what = fn (AutomationRule $r) => array_column($engine->candidates($r, Carbon::today()), 'what');
        $this->assertSame(['Task “Due today” is due today', 'Task “Due Friday” is due 6 Nov'], $what($this->rule($pm, $kiln, ['trigger' => 'task_due_soon', 'param' => '2', 'actions' => $notify])));
        $this->assertSame(['Milestone “Kiln cold” passed its date (3 Nov) unfinished'], $what($this->rule($pm, $kiln, ['trigger' => 'milestone_overdue', 'actions' => $notify])));
        $this->assertSame(['Milestone “Kiln hot” is due 5 Nov'], $what($this->rule($pm, $kiln, ['trigger' => 'milestone_due_soon', 'param' => '1', 'actions' => $notify])));
        $this->assertSame(['Risk '.$risk->ref().' “Bricks late” is due for review (4 Nov)'], $what($this->rule($pm, $kiln, ['trigger' => 'risk_review_due', 'actions' => $notify])));

        $weekly = $this->rule($pm, $kiln, ['trigger' => 'weekly', 'param' => '1', 'actions' => $notify]);
        $this->assertSame([], $what($weekly), 'Wednesday is not Monday');
        $this->assertSame('When it is Monday in Kiln reline, then notify the project owner.', AutomationCatalog::describe($weekly, 'Kiln reline'));
        $this->sweep('2026-11-09 08:00:00');
        $this->sweep('2026-11-09 12:00:00');
        $this->sweep('2026-11-16 08:00:00');
        $this->assertSame(['week:2026-W46', 'week:2026-W47'], AutomationRun::query()->where('rule_id', $weekly->id)->orderBy('id')->pluck('key')->all());
    }

    public function test_an_approaching_deadline_starts_one_progress_review(): void
    {
        ['pm' => $pm, 'kiln' => $kiln] = $this->business();
        Notification::fake();
        $rule = $this->rule($pm, $kiln, ['trigger' => 'project_deadline', 'param' => '14', 'actions' => [['type' => 'start_report'], ['type' => 'notify', 'to' => ['business_owner'], 'note' => 'The review draft is ready.']]]);
        $this->assertSame('When the project\'s due date is two weeks away in Kiln reline, then start a progress review (a status report draft for the project), then notify the business owner with the note “The review draft is ready.”.', AutomationCatalog::describe($rule, 'Kiln reline'));

        $this->sweep('2026-11-04 09:05:00');
        $this->sweep('2026-11-05 09:05:00');
        $report = ProjectReport::query()->sole();
        $this->assertSame(['draft', $pm->id], [$report->status, $report->created_by]);
        $run = AutomationRun::query()->sole();
        $this->assertSame(['The project is due on 15 Nov, in 11 days', 'Started progress review '.$report->ref().' as a draft'], [$run->what, $run->steps[0]['summary']]);

        $kiln->update(['due_date' => '2026-11-18']);
        $this->sweep('2026-11-06 09:05:00');
        $this->assertSame(1, ProjectReport::query()->count(), 'a new due date fires again, and finds the draft already open');
        $this->assertSame('A progress review was already open ('.$report->ref().')', AutomationRun::query()->latest('id')->first()->steps[0]['summary']);
    }

    public function test_a_wait_carries_on_later_and_stops_when_it_no_longer_applies(): void
    {
        ['pm' => $pm, 'ed' => $ed, 'kiln' => $kiln] = $this->business();
        Notification::fake();
        $a = $kiln->tasks()->create(['title' => 'Inspect lining', 'status' => 'in_progress', 'priority' => 'medium', 'assignee_id' => $ed->id]);
        $b = $kiln->tasks()->create(['title' => 'Inspect burner', 'status' => 'in_progress', 'priority' => 'medium', 'assignee_id' => $ed->id]);
        $this->rule($pm, $kiln, ['trigger' => 'task_status', 'param' => 'review', 'actions' => [
            ['type' => 'notify', 'to' => ['project_owner'], 'note' => ''],
            ['type' => 'wait', 'days' => '2', 'recheck' => true],
            ['type' => 'escalate'],
        ]]);

        $this->actingAs($ed);
        app(ProjectWork::class)->updateTask($a, ['status' => 'review'], $ed);
        app(ProjectWork::class)->updateTask($b, ['status' => 'review'], $ed);
        auth()->logout();
        $runA = AutomationRun::query()->where('subject_id', $a->id)->sole();
        $this->assertSame(['waiting', '2026-11-06 09:00:00', ['Notified Palesa PM', 'Wait until 6 Nov, 09:00 UTC']], [$runA->status, $runA->resume_at->toDateTimeString(), array_column($runA->steps, 'summary')]);

        $this->assertSame(0, $this->sweep('2026-11-05 09:05:00')['resumed'], 'not before the wait is over');
        $this->actingAs($ed);
        app(ProjectWork::class)->updateTask($b->fresh(), ['status' => 'done'], $ed);
        auth()->logout();

        $this->assertSame(2, $this->sweep('2026-11-06 09:05:00')['resumed']);
        $runA->refresh();
        $this->assertSame(['done', 'urgent'], [$runA->status, $a->fresh()->priority]);
        $this->assertSame('Made the task urgent and told Palesa PM', $runA->steps[2]['summary']);
        $runB = AutomationRun::query()->where('subject_id', $b->id)->sole();
        $this->assertSame(['done', 'Stopped after the wait: it no longer applies.', 'medium'], [$runB->status, collect($runB->steps)->last()['summary'], $b->fresh()->priority]);
        $this->assertSame(0, $this->sweep('2026-11-06 10:05:00')['resumed']);
    }

    public function test_dated_rules_preview_what_is_due_and_a_wait_needs_a_step_after_it(): void
    {
        ['pm' => $pm, 'kiln' => $kiln] = $this->business();
        $kiln->tasks()->create(['title' => 'Order bricks', 'status' => 'todo', 'priority' => 'high', 'due_date' => '2026-11-02']);
        $this->actingAs($pm);
        $rule = app(Automations::class)->save(null, $pm->currentTeam, $pm, ['name' => 'Late work', 'project_id' => (string) $kiln->id, 'trigger' => 'task_overdue', 'param' => '', 'conditions' => [], 'actions' => [['type' => 'notify', 'to' => ['project_owner'], 'note' => '']]]);

        $pv = app(Automations::class)->preview($rule, $pm->currentTeam);
        $this->assertSame([true, 1, 'Task “Order bricks” is overdue (it was due 2 Nov)', false], [$pv['dated'], $pv['total'], $pv['items'][0]['what'], $pv['items'][0]['done']]);
        $this->assertSame(0, AutomationRun::query()->count());
        $this->assertSame(['fired' => 0, 'resumed' => 0], app(Automations::class)->sweep(), 'a rule that is off does nothing');
        Livewire::actingAs($pm)->test(Board::class)->call('startNew', 'overdue_task')->set('form.project_id', (string) $kiln->id)
            ->assertSee('What it would act on at its next check')->assertSee('Task “Order bricks” is overdue (it was due 2 Nov)')
            ->assertSee('then wait 2 days and stop if it no longer applies, then make the task urgent');

        try {
            AutomationCatalog::clean(['name' => 'X', 'project_id' => (string) $kiln->id, 'trigger' => 'task_overdue', 'param' => '', 'conditions' => [], 'actions' => [['type' => 'notify', 'to' => ['project_owner'], 'note' => ''], ['type' => 'wait', 'days' => 2]]], $pm->currentTeam, $pm);
            $this->fail('a wait with nothing after it was accepted');
        } catch (ValidationException $e) {
            $this->assertSame('A wait needs a step after it.', $e->errors()['actions'][0]);
        }

        $this->artisan('automations:sweep')->expectsOutputToContain('Automations: 0 run(s) started, 0 resumed.')->assertSuccessful();
        $this->assertTrue(ProjectTask::query()->exists());
    }
}
