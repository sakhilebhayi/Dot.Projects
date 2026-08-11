<?php

namespace Tests\Feature;

use App\Broadcasting\ProjectChannelAuthorizer;
use App\Events\ProjectBoardUpdated;
use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectBoardRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $owner): Project
    {
        return Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Realtime Project',
            'status' => 'active',
        ]);
    }

    public function test_moving_a_task_broadcasts_a_board_updated_event(): void
    {
        Event::fake([ProjectBoardUpdated::class]);

        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $task = $project->tasks()->create(['title' => 'Wire the board', 'status' => 'backlog']);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('moveTask', $task->id, 'in_progress');

        Event::assertDispatched(ProjectBoardUpdated::class, fn ($event) => $event->project->is($project));
    }

    public function test_assigning_a_task_broadcasts_a_board_updated_event(): void
    {
        Event::fake([ProjectBoardUpdated::class]);

        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $task = $project->tasks()->create(['title' => 'Wire the board', 'status' => 'todo']);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('assignTask', $task->id, $owner->id);

        Event::assertDispatched(ProjectBoardUpdated::class);
    }

    public function test_a_blocked_move_does_not_broadcast_since_nothing_changed(): void
    {
        Event::fake([ProjectBoardUpdated::class]);

        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $blocker = $project->tasks()->create(['title' => 'Blocker', 'status' => 'todo']);
        $task = $project->tasks()->create(['title' => 'Blocked task', 'status' => 'in_progress']);
        $task->dependencies()->attach($blocker->id);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('moveTask', $task->id, 'done');

        Event::assertNotDispatched(ProjectBoardUpdated::class);
    }

    public function test_refresh_board_invalidates_the_cached_computed_task_list(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $project->tasks()->create(['title' => 'Existing task', 'status' => 'backlog']);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->assertSee('Existing task')
            ->call('refreshBoard')
            ->assertSee('Existing task');
    }

    // -----------------------------------------------------------------------
    // Channel authorization
    // -----------------------------------------------------------------------

    public function test_a_team_member_is_authorized_on_their_projects_channel(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);

        $this->assertTrue(ProjectChannelAuthorizer::authorize($owner, $project->id));
    }

    public function test_an_outsider_is_not_authorized_on_someone_elses_project_channel(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);

        $this->assertFalse(ProjectChannelAuthorizer::authorize($outsider, $project->id));
    }

    public function test_authorization_is_false_for_a_nonexistent_project(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->assertFalse(ProjectChannelAuthorizer::authorize($user, 999999));
    }
}
