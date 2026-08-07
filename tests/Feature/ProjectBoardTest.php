<?php

namespace Tests\Feature;

use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_a_task_updates_its_status(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Board Project',
            'status' => 'active',
        ]);
        $task = $project->tasks()->create(['title' => 'Wire the board', 'status' => 'backlog']);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('moveTask', $task->id, 'in_progress');

        $this->assertEquals('in_progress', $task->fresh()->status);
    }

    public function test_assigning_a_task_to_a_team_member_notifies_them(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Assignment Project',
            'status' => 'active',
        ]);
        $task = $project->tasks()->create(['title' => 'Build the widget', 'status' => 'todo']);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('assignTask', $task->id, $member->id);

        $this->assertEquals($member->id, $task->fresh()->assignee_id);
        Notification::assertSentTo($member, TaskAssignedNotification::class);
    }

    public function test_assigning_a_task_to_someone_outside_the_team_is_rejected(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->create();

        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Locked Project',
            'status' => 'active',
        ]);
        $task = $project->tasks()->create(['title' => 'Sensitive task', 'status' => 'todo']);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('assignTask', $task->id, $outsider->id)
            ->assertForbidden();

        $this->assertNull($task->fresh()->assignee_id);
    }
}
