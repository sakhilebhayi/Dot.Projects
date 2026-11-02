<?php

namespace Tests\Feature;

use App\Livewire\Portfolio\Board;
use App\Livewire\Projects\ScheduleView;
use App\Livewire\Projects\TaskPanel;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Notifications\GovernanceNotification;
use App\Services\Governance;
use App\Services\PortfolioOverview;
use App\Services\ProjectPeople;
use App\Services\ProjectWork;
use App\Services\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P8b: work in one project may wait on work in another project of the same business. Never
 * across businesses, never on an archived project, never a loop (checked across projects), never
 * naming work in a project the reader cannot see. The holding project's owner and managers are
 * told; the schedule flags starts before external work is due; the portfolio shows the links, the
 * major risks and a roadmap of milestones.
 */
class CrossProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
    }

    /** @return array{boss: User, pm: User, mgr: User, conveyor: Project, kiln: Project, belt: ProjectTask, reline: ProjectTask} */
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
        $mgr = $make('Musa Manager');
        $conveyor = Project::create(['team_id' => $team->id, 'owner_id' => $pm->id, 'name' => 'Conveyor upgrade', 'status' => 'active']);
        $kiln = Project::create(['team_id' => $team->id, 'owner_id' => $boss->id, 'name' => 'Kiln reline', 'status' => 'active']);
        $this->actingAs($boss);
        app(ProjectPeople::class)->add($kiln, $boss, $mgr->id, 'manager');
        $belt = $conveyor->tasks()->create(['title' => 'Fit new belt', 'status' => 'todo', 'priority' => 'high', 'start_date' => '2026-11-10', 'due_date' => '2026-11-12']);
        $reline = $kiln->tasks()->create(['title' => 'Reline the kiln', 'status' => 'in_progress', 'priority' => 'high', 'start_date' => '2026-11-02', 'due_date' => '2026-11-15']);

        return ['boss' => $boss, 'pm' => $pm, 'mgr' => $mgr, 'conveyor' => $conveyor->fresh(), 'kiln' => $kiln->fresh(), 'belt' => $belt, 'reline' => $reline];
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

    public function test_a_task_waits_for_work_in_another_project_and_its_holders_are_told(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'mgr' => $mgr, 'conveyor' => $conveyor, 'kiln' => $kiln, 'belt' => $belt, 'reline' => $reline] = $this->business();
        Notification::fake();

        Livewire::actingAs($pm)->test(TaskPanel::class, ['project' => $conveyor, 'task' => $belt])
            ->assertSee('Kiln reline')
            ->set('depProject', (string) $kiln->id)->assertSee('Reline the kiln')->assertSee('Its owner and managers are told')
            ->set('newDependency', (string) $reline->id)->call('addDependency')->assertHasNoErrors()
            ->assertSee('In Kiln reline');
        $this->assertTrue(DB::table('task_dependencies')->where('successor_id', $belt->id)->where('predecessor_id', $reline->id)->exists());
        Notification::assertSentTo([$boss, $mgr], GovernanceNotification::class, fn ($n) => $n->type === 'dependency_external' && str_contains($n->message, '“Conveyor upgrade”'));
        Notification::assertNotSentTo($pm, GovernanceNotification::class);
        $this->assertTrue($belt->fresh()->isBlocked());

        $this->actingAs($pm);
        $this->refuses(fn () => app(ProjectWork::class)->updateTask($belt->fresh(), ['status' => 'in_progress'], $pm), 'status', '“Reline the kiln (in Kiln reline)”');
        Livewire::actingAs($mgr)->test(TaskPanel::class, ['project' => $kiln, 'task' => $reline])->assertSee('Holds up')->assertSee('Fit new belt')->assertSee('In Conveyor upgrade');

        $this->actingAs($boss);
        $this->refuses(fn () => app(ProjectWork::class)->addDependency($reline, $belt, $boss), 'dependency', 'That would be a loop');
    }

    public function test_links_stay_inside_the_business_and_off_archived_projects(): void
    {
        ['pm' => $pm, 'conveyor' => $conveyor, 'kiln' => $kiln, 'belt' => $belt, 'reline' => $reline] = $this->business();
        $other = User::factory()->withPersonalTeam()->create();
        $theirs = Project::withoutTeamScope(fn () => Project::create(['team_id' => $other->currentTeam->id, 'owner_id' => $other->id, 'name' => 'Theirs', 'status' => 'active']));
        $foreign = Project::withoutTeamScope(fn () => $theirs->tasks()->create(['title' => 'Elsewhere', 'status' => 'todo', 'priority' => 'low']));

        $this->actingAs($pm);
        $work = app(ProjectWork::class);
        $this->refuses(fn () => $work->addDependency($belt, $foreign, $pm), 'dependency', 'a project of this business');

        $kiln->forceFill(['archived_at' => now()])->save();
        $this->refuses(fn () => $work->addDependency($belt, $reline->fresh(), $pm), 'dependency', 'is archived');
        Livewire::actingAs($pm)->test(TaskPanel::class, ['project' => $conveyor, 'task' => $belt])->assertDontSee('Kiln reline')
            ->set('depProject', (string) $kiln->id)->set('newDependency', (string) $reline->id)->call('addDependency')->assertNotFound();
        $this->assertSame(0, DB::table('task_dependencies')->count());
    }

    public function test_work_in_a_project_you_cannot_see_is_never_named(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'conveyor' => $conveyor, 'belt' => $belt] = $this->business();
        $secret = Project::create(['team_id' => $conveyor->team_id, 'owner_id' => $boss->id, 'name' => 'Retrenchments', 'status' => 'active', 'visibility' => 'private']);
        $consult = $secret->tasks()->create(['title' => 'Consult the union', 'status' => 'todo', 'priority' => 'high', 'due_date' => '2026-11-20']);

        $this->actingAs($pm);
        $this->refuses(fn () => app(ProjectWork::class)->addDependency($belt, $consult, $pm), 'dependency');
        Livewire::actingAs($pm)->test(TaskPanel::class, ['project' => $conveyor, 'task' => $belt])->assertDontSee('Retrenchments');

        $this->actingAs($boss);
        app(ProjectWork::class)->addDependency($belt, $consult, $boss);

        Livewire::actingAs($pm)->test(TaskPanel::class, ['project' => $conveyor, 'task' => $belt])
            ->assertSee('A task in a project you cannot see')->assertSee('Another project')
            ->assertDontSee('Consult the union')->assertDontSee('Retrenchments')->assertDontSee('20 Nov');
        $this->actingAs($pm);
        $this->refuses(fn () => app(ProjectWork::class)->updateTask($belt->fresh(), ['status' => 'in_progress'], $pm), 'status', '“a task in a project you cannot see”');
        $this->assertSame(['Starts before a task it waits for in a project you cannot see is due.'], (new Schedule($conveyor))->row($belt)['conflicts']);
        $this->actingAs($pm)->get(route('projects.show', $conveyor))->assertOk()->assertDontSee('Consult the union');
        Livewire::actingAs($pm)->test(ScheduleView::class, ['project' => $conveyor])->assertDontSee('Consult the union')->assertSee('1 task waits on other projects, 1 still held up.');

        $this->actingAs($boss);
        $this->assertSame(['Starts before “Consult the union” in Retrenchments finishes on 20 Nov.'], (new Schedule($conveyor))->row($belt)['conflicts']);
    }

    public function test_the_schedule_flags_external_conflicts_and_counts_held_up_work_in_float(): void
    {
        ['boss' => $boss, 'conveyor' => $conveyor, 'kiln' => $kiln, 'belt' => $belt, 'reline' => $reline] = $this->business();
        $this->actingAs($boss);
        app(ProjectWork::class)->addDependency($belt, $reline, $boss);

        $here = new Schedule($conveyor);
        $this->assertSame(['Starts before “Reline the kiln” in Kiln reline finishes on 15 Nov.'], $here->row($belt)['conflicts']);
        $this->assertSame(['waits' => 1, 'open' => 1, 'holds' => 0], $here->crossProject());

        $there = new Schedule($kiln);
        $this->assertSame(-6, $there->row($reline)['float'], 'it must finish by 9 Nov, the day before the belt starts');
        $this->assertTrue($there->row($reline)['critical']);
        $this->assertSame(['waits' => 0, 'open' => 0, 'holds' => 1], $there->crossProject());

        $reline->update(['status' => 'done']);
        $this->assertSame([], (new Schedule($conveyor))->row($belt)['conflicts'], 'done work holds nothing up');
    }

    public function test_the_portfolio_shows_links_major_risks_and_a_roadmap(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'conveyor' => $conveyor, 'kiln' => $kiln, 'belt' => $belt, 'reline' => $reline] = $this->business();
        $this->actingAs($boss);
        app(ProjectWork::class)->addDependency($belt, $reline, $boss);
        $gov = app(Governance::class);
        $gov->addRisk($kiln, $boss, ['title' => 'Refractory bricks arrive late', 'probability' => 4, 'impact' => 4]);
        $gov->addRisk($conveyor, $boss, ['title' => 'Minor paint delay', 'probability' => 1, 'impact' => 2]);
        $conveyor->milestones()->create(['title' => 'Belt running', 'status' => 'pending', 'due_date' => '2026-11-18']);
        $kiln->milestones()->create(['title' => 'Kiln cold', 'status' => 'pending', 'due_date' => '2026-10-20']);
        $kiln->milestones()->create(['title' => 'Old trial', 'status' => 'completed', 'due_date' => '2026-10-01']);
        $kiln->milestones()->create(['title' => 'Handover', 'status' => 'pending', 'due_date' => null]);

        $svc = app(PortfolioOverview::class);
        $links = $svc->links(collect([$conveyor, $kiln]));
        $this->assertCount(1, $links['pairs']);
        $this->assertSame(['Conveyor upgrade', 'Kiln reline', 1, 1, 1], [$links['pairs'][0]['waiting']->name, $links['pairs'][0]['on']->name, $links['pairs'][0]['links'], $links['pairs'][0]['open'], $links['pairs'][0]['conflicts']]);
        $this->assertSame(1, $svc->links(collect([$conveyor]))['outside'], 'a link to a project outside the set is counted, not named');

        $risks = $svc->majorRisks(collect([$conveyor, $kiln]));
        $this->assertSame([1, 'Refractory bricks arrive late'], [$risks['total'], $risks['items'][0]['risk']->title]);

        $road = $svc->roadmap(collect([$conveyor, $kiln]), Carbon::today());
        $this->assertSame(['2026-11-01', '2027-04-30', 1], [$road['from']->toDateString(), $road['to']->toDateString(), $road['undated']]);
        $kiln->milestones()->create(['title' => 'Burner test', 'status' => 'completed', 'due_date' => '2026-11-02']);
        $road = $svc->roadmap(collect([$conveyor, $kiln]), Carbon::today());
        $marks = collect($road['lanes'])->mapWithKeys(fn ($l) => [$l['project']->name => array_map(fn ($m) => [array_column($m['items'], 'title'), $m['state'], $m['at']], $l['marks'])])->all();
        $this->assertSame(['Conveyor upgrade' => [[['Belt running'], 'due', 9.44]], 'Kiln reline' => [[['Kiln cold', 'Burner test'], 'late', 0.0]]], $marks, 'marks within four days share one, coloured by the worst');

        Livewire::actingAs($boss)->test(Board::class)->assertSee('Roadmap')->assertSee('Belt running, 18 Nov')->assertSee('2 milestones: Kiln cold, 20 Oct, late; Burner test, 2 Nov, complete')
            ->assertSee('R-01 Refractory bricks arrive late')->assertDontSee('Minor paint delay')
            ->assertSee('waits on')->assertSee('1 starts before the work it waits for is due');
        $this->actingAs($pm)->get(route('portfolio.index'))->assertOk()->assertSee('Work between projects');
    }
}
