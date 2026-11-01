<?php

namespace Tests\Feature;

use App\Livewire\Projects\ScheduleView;
use App\Models\Activity;
use App\Models\Baseline;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\ProjectWork;
use App\Services\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P4: the schedule. Float from a backward pass over finish-to-start dependencies, the
 * critical path, conflicts shown rather than refused, rescheduling by drag or keys checked on the
 * server, and numbered baselines that keep the dates as they were.
 */
class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
    }

    /** @return array{owner: User, viewer: User, project: Project, t: array<string, ProjectTask>} */
    private function plan(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $viewer = User::factory()->create(['current_team_id' => $owner->currentTeam->id]);
        $owner->currentTeam->users()->attach($viewer, ['role' => 'viewer']);
        $project = Project::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => 'Fleet rollout', 'status' => 'active', 'start_date' => '2026-11-02', 'due_date' => '2026-11-30']);
        $ms = $project->milestones()->create(['title' => 'Trucks on site', 'status' => 'pending', 'due_date' => '2026-11-20']);
        $make = fn (string $title, ?string $start, ?string $due, string $status = 'todo', ?int $milestone = null) => $project->tasks()->create(['title' => $title, 'status' => $status, 'priority' => 'medium', 'start_date' => $start, 'due_date' => $due, 'milestone_id' => $milestone]);
        $t = [
            'clear' => $make('Clear customs', '2026-11-02', '2026-11-06', 'todo', $ms->id),
            'fit' => $make('Fit the trucks', '2026-11-07', '2026-11-30', 'backlog', $ms->id),
            'train' => $make('Train drivers', '2026-11-03', '2026-11-10'),
            'paint' => $make('Paint the fleet', '2026-11-05', '2026-11-12'),
            'order' => $make('Order the trucks', '2026-10-20', '2026-11-01', 'done'),
            'insure' => $make('Insure the fleet', null, null),
        ];
        $this->actingAs($owner);
        $work = app(ProjectWork::class);
        $work->addDependency($t['fit'], $t['clear'], $owner);
        $work->addDependency($t['paint'], $t['clear'], $owner);

        return ['owner' => $owner, 'viewer' => $viewer, 'project' => $project->fresh(), 't' => array_map(fn ($x) => $x->fresh(), $t)];
    }

    public function test_float_critical_path_and_conflicts_come_from_the_dates(): void
    {
        ['project' => $project, 't' => $t] = $this->plan();
        $s = new Schedule($project);

        $float = fn (string $k) => $s->row($t[$k])['float'];
        $this->assertSame(0, $float('fit'), 'Fit ends on the due date');
        $this->assertSame(0, $float('clear'), 'Fit has to start the day after Clear is due');
        $this->assertSame(20, $float('train'));
        $this->assertSame(18, $float('paint'));
        $this->assertNull($float('order'), 'done work has no float');
        $this->assertSame(['Clear customs', 'Fit the trucks'], $s->critical()->pluck('title')->sort()->values()->all());

        $this->assertSame(1, $s->conflictCount());
        $this->assertStringContainsString('Clear customs', $s->row($t['paint'])['conflicts'][0]);
        $this->assertSame(['Insure the fleet'], $s->unscheduled()->pluck('title')->all());
        $this->assertSame('2026-11-30', $s->forecastFinish()->toDateString());
        $this->assertSame('2026-11-30', $s->milestoneForecast($project->milestones()->first())->toDateString(), 'the milestone lands when its last task does');

        $this->get(route('projects.schedule', $project))->assertOk()
            ->assertSee('Work as planned finishes')->assertSee('2 open tasks are on the critical path, 1 dependency broken', false)
            ->assertSee('Conflict')->assertSee('Not on the schedule')->assertSee('Insure the fleet');
    }

    public function test_rescheduling_is_checked_and_recorded(): void
    {
        ['owner' => $owner, 'viewer' => $viewer, 'project' => $project, 't' => $t] = $this->plan();
        $view = fn (?User $u = null) => Livewire::actingAs($u ?? $owner)->test(ScheduleView::class, ['project' => $project]);

        $view()->call('reschedule', $t['train']->id, '2026-11-04', '2026-11-11')->assertHasNoErrors();
        $this->assertSame(['2026-11-04', '2026-11-11'], [$t['train']->fresh()->start_date->toDateString(), $t['train']->fresh()->due_date->toDateString()]);
        $line = Activity::query()->where('action', 'task.rescheduled')->sole();
        $this->assertSame(['from' => '2026-11-10', 'to' => '2026-11-11'], $line->changes['due_date']);

        $view()->call('reschedule', $t['train']->id, '2026-11-12', '2026-11-11')->assertHasErrors('schedule');
        $view()->call('reschedule', $t['order']->id, '2026-10-21', '2026-11-02')->assertHasErrors('schedule');
        $this->assertSame('2026-11-01', $t['order']->fresh()->due_date->toDateString(), 'done work keeps its dates');
        $view()->call('reschedule', $t['train']->id, null, 'not a date')->assertHasErrors('due');

        $view($viewer)->call('reschedule', $t['train']->id, '2026-11-05', '2026-11-12')->assertForbidden();
        $other = Project::create(['team_id' => $project->team_id, 'owner_id' => $owner->id, 'name' => 'Other', 'status' => 'active']);
        $foreign = $other->tasks()->create(['title' => 'Elsewhere', 'status' => 'todo', 'due_date' => '2026-11-10']);
        $view()->call('reschedule', $foreign->id, null, '2026-11-12')->assertNotFound();
        $this->assertSame('2026-11-10', $foreign->fresh()->due_date->toDateString());

        app(ProjectWork::class)->archive($project, $owner);
        Livewire::actingAs($owner)->test(ScheduleView::class, ['project' => $project->fresh()])->call('reschedule', $t['train']->id, null, '2026-11-20')->assertForbidden();
        $this->assertSame(1, Activity::query()->where('action', 'task.rescheduled')->count());
    }

    public function test_baselines_are_numbered_and_keep_the_dates_as_they_were(): void
    {
        ['owner' => $owner, 'viewer' => $viewer, 'project' => $project, 't' => $t] = $this->plan();
        $view = Livewire::actingAs($owner)->test(ScheduleView::class, ['project' => $project]);
        $view->set('baselineNote', 'Agreed with the sponsor')->call('setBaseline')->assertHasNoErrors();
        $first = Baseline::query()->sole();
        $this->assertSame([1, 'Agreed with the sponsor', $owner->id], [$first->number, $first->note, $first->created_by]);
        $this->assertSame(1 + 1 + 6, $first->items()->count(), 'the project, one milestone and six tasks');
        $this->assertSame((string) $first->id, $view->get('baseline'));

        $view->call('reschedule', $t['fit']->id, '2026-11-07', '2026-12-04');
        $project->forceFill(['due_date' => '2026-12-04'])->save();
        $view->call('setBaseline');
        $this->assertSame([2, 1], $project->baselines()->pluck('number')->all(), 'newest first');

        $map = Schedule::baselineMap($first);
        $this->assertSame('2026-11-30', $map['task'][$t['fit']->id]->due_date->toDateString(), 'baseline 1 still holds the old date');
        $this->assertSame('2026-11-30', $map['project'][$project->id]->due_date->toDateString());

        $this->get(route('projects.schedule', ['project' => $project, 'baseline' => $first->id]))->assertOk()->assertSee('Baseline 1: 7 Nov to 30 Nov');
        $this->get(route('projects.show', $project))->assertSee('Baseline 2 was due 4 Dec')->assertSee('Tasks as dated finish');
        $this->assertSame(2, Activity::query()->where('action', 'baseline.set')->count());

        Livewire::actingAs($viewer)->test(ScheduleView::class, ['project' => $project])->call('setBaseline')->assertForbidden();
        $this->actingAs($viewer)->get(route('projects.schedule', $project))->assertOk()->assertDontSee('Save baseline');
        $this->assertSame(2, Baseline::query()->count());
    }

    public function test_the_schedule_is_team_private(): void
    {
        ['project' => $project] = $this->plan();
        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger)->get(route('projects.schedule', $project))->assertNotFound();
    }
}
