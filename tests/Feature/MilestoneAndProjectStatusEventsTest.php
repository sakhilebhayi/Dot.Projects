<?php

namespace Tests\Feature;

use App\Events\MilestoneReached;
use App\Events\ProjectClosed;
use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers wiring the `delivery.milestone.reached` / `delivery.project.closed`
 * event contract (wiki.md §5, roadmap item 1) to the actual Milestone/Project
 * status transitions via Milestone::syncStatusFromTasks() and
 * Project::closeIfAllMilestonesCompleted(), both triggered from
 * ProjectBoard::moveTask().
 */
class MilestoneAndProjectStatusEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_a_milestones_only_task_to_done_completes_the_milestone_and_dispatches_the_event(): void
    {
        Event::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id'  => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name'     => 'Milestone Project',
            'status'   => 'active',
        ]);
        $milestone = $project->milestones()->create(['title' => 'Beta', 'status' => 'pending']);
        $task = $project->tasks()->create([
            'milestone_id' => $milestone->id,
            'title'        => 'Ship the beta',
            'status'       => 'review',
        ]);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('moveTask', $task->id, 'done');

        $this->assertEquals('completed', $milestone->fresh()->status);
        Event::assertDispatched(MilestoneReached::class, fn ($event) => $event->milestone->is($milestone));
    }

    public function test_moving_a_task_to_in_progress_advances_the_milestone_without_completing_it(): void
    {
        Event::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id'  => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name'     => 'In Progress Project',
            'status'   => 'active',
        ]);
        $milestone = $project->milestones()->create(['title' => 'Alpha', 'status' => 'pending']);
        $task = $project->tasks()->create([
            'milestone_id' => $milestone->id,
            'title'        => 'Start the alpha',
            'status'       => 'backlog',
        ]);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('moveTask', $task->id, 'in_progress');

        $this->assertEquals('in_progress', $milestone->fresh()->status);
        Event::assertNotDispatched(MilestoneReached::class);
    }

    public function test_completing_the_last_milestone_closes_the_project_and_dispatches_the_event(): void
    {
        Event::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id'  => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name'     => 'Closing Project',
            'status'   => 'active',
        ]);
        $doneMilestone = $project->milestones()->create(['title' => 'Done already', 'status' => 'completed']);
        $lastMilestone = $project->milestones()->create(['title' => 'Final push', 'status' => 'pending']);
        $task = $project->tasks()->create([
            'milestone_id' => $lastMilestone->id,
            'title'        => 'Wrap it up',
            'status'       => 'review',
        ]);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('moveTask', $task->id, 'done');

        $this->assertEquals('completed', $lastMilestone->fresh()->status);
        $this->assertEquals('completed', $project->fresh()->status);
        Event::assertDispatched(ProjectClosed::class, fn ($event) => $event->project->is($project));
    }

    public function test_an_on_hold_project_is_not_auto_closed(): void
    {
        Event::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id'  => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name'     => 'Paused Project',
            'status'   => 'on_hold',
        ]);
        $milestone = $project->milestones()->create(['title' => 'Only milestone', 'status' => 'pending']);
        $task = $project->tasks()->create([
            'milestone_id' => $milestone->id,
            'title'        => 'Last task',
            'status'       => 'review',
        ]);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('moveTask', $task->id, 'done');

        $this->assertEquals('completed', $milestone->fresh()->status);
        $this->assertEquals('on_hold', $project->fresh()->status);
        Event::assertNotDispatched(ProjectClosed::class);
    }
}
