<?php

namespace Tests\Feature;

use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TimeLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $owner): Project
    {
        return Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Time Logging Project',
            'status' => 'active',
        ]);
    }

    public function test_a_team_member_can_log_time_against_a_task(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $task = $project->tasks()->create(['title' => 'Build the widget', 'status' => 'in_progress']);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('logTime', $task->id, 45, 'Initial pass');

        $this->assertDatabaseHas('time_logs', [
            'project_task_id' => $task->id,
            'user_id' => $owner->id,
            'minutes' => 45,
            'note' => 'Initial pass',
        ]);
    }

    public function test_logged_time_accumulates_across_multiple_entries(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $task = $project->tasks()->create(['title' => 'Build the widget', 'status' => 'in_progress']);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('logTime', $task->id, 30)
            ->call('logTime', $task->id, 15);

        $this->assertSame(45, $task->fresh()->totalMinutesLogged());
    }

    public function test_project_total_sums_time_across_every_task(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $taskA = $project->tasks()->create(['title' => 'Task A', 'status' => 'todo']);
        $taskB = $project->tasks()->create(['title' => 'Task B', 'status' => 'todo']);
        $taskA->timeLogs()->create(['user_id' => $owner->id, 'minutes' => 20, 'logged_at' => now()]);
        $taskB->timeLogs()->create(['user_id' => $owner->id, 'minutes' => 25, 'logged_at' => now()]);

        $this->assertSame(45, $project->totalMinutesLogged());
    }

    public function test_zero_or_negative_minutes_are_rejected(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $task = $project->tasks()->create(['title' => 'Build the widget', 'status' => 'in_progress']);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('logTime', $task->id, 0);

        $this->assertSame(0, $task->fresh()->totalMinutesLogged());
    }

    public function test_a_task_from_another_project_cannot_have_time_logged_against_it(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $projectA = $this->project($owner);
        $projectB = $this->project($owner);
        $taskB = $projectB->tasks()->create(['title' => 'Task B', 'status' => 'todo']);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $projectA])
            ->call('logTime', $taskB->id, 30)
            ->assertForbidden();
    }

    public function test_an_outsider_cannot_log_time(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->create();
        $project = $this->project($owner);
        $task = $project->tasks()->create(['title' => 'Build the widget', 'status' => 'todo']);

        Livewire::actingAs($outsider)
            ->test(ProjectBoard::class, ['project' => $project])
            ->assertForbidden();

        $this->assertSame(0, $task->fresh()->totalMinutesLogged());
    }
}
