<?php

namespace Tests\Feature;

use App\Events\DependencyBlocked;
use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class TaskDependencyTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $owner): Project
    {
        return Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Dependency Project',
            'status' => 'active',
        ]);
    }

    public function test_a_task_with_an_incomplete_dependency_is_blocked(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $blocker = $project->tasks()->create(['title' => 'Design API', 'status' => 'todo']);
        $task = $project->tasks()->create(['title' => 'Build client', 'status' => 'todo']);
        $task->dependencies()->attach($blocker->id);

        $this->assertTrue($task->fresh()->isBlocked());
    }

    public function test_a_task_is_unblocked_once_its_dependency_is_done(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $blocker = $project->tasks()->create(['title' => 'Design API', 'status' => 'done']);
        $task = $project->tasks()->create(['title' => 'Build client', 'status' => 'todo']);
        $task->dependencies()->attach($blocker->id);

        $this->assertFalse($task->fresh()->isBlocked());
    }

    public function test_moving_a_blocked_task_to_done_is_rejected_and_dispatches_dependency_blocked(): void
    {
        Event::fake([DependencyBlocked::class]);

        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $blocker = $project->tasks()->create(['title' => 'Design API', 'status' => 'todo']);
        $task = $project->tasks()->create(['title' => 'Build client', 'status' => 'in_progress']);
        $task->dependencies()->attach($blocker->id);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('moveTask', $task->id, 'done')
            ->assertSet('blockedMessage', fn ($message) => str_contains($message, 'Design API'));

        $this->assertSame('in_progress', $task->fresh()->status);
        Event::assertDispatched(DependencyBlocked::class, fn ($event) => $event->task->is($task) && $event->blockingTask->is($blocker)
        );
    }

    public function test_moving_an_unblocked_task_to_done_succeeds(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $blocker = $project->tasks()->create(['title' => 'Design API', 'status' => 'done']);
        $task = $project->tasks()->create(['title' => 'Build client', 'status' => 'in_progress']);
        $task->dependencies()->attach($blocker->id);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('moveTask', $task->id, 'done');

        $this->assertSame('done', $task->fresh()->status);
    }

    public function test_can_add_and_remove_a_dependency_from_the_board(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $a = $project->tasks()->create(['title' => 'Task A', 'status' => 'todo']);
        $b = $project->tasks()->create(['title' => 'Task B', 'status' => 'todo']);

        $component = Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('addDependency', $a->id, $b->id);

        $this->assertTrue($a->dependencies()->where('project_tasks.id', $b->id)->exists());

        $component->call('removeDependency', $a->id, $b->id);

        $this->assertFalse($a->fresh()->dependencies()->where('project_tasks.id', $b->id)->exists());
    }

    public function test_a_task_cannot_depend_on_itself(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $task = $project->tasks()->create(['title' => 'Solo task', 'status' => 'todo']);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('addDependency', $task->id, $task->id);

        $this->assertSame(0, $task->fresh()->dependencies()->count());
    }

    public function test_a_direct_circular_dependency_is_rejected(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $a = $project->tasks()->create(['title' => 'Task A', 'status' => 'todo']);
        $b = $project->tasks()->create(['title' => 'Task B', 'status' => 'todo']);
        $a->dependencies()->attach($b->id); // A depends on B

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('addDependency', $b->id, $a->id) // B depends on A -- would cycle
            ->assertSet('blockedMessage', fn ($message) => str_contains($message, 'circular'));

        $this->assertFalse($b->fresh()->dependencies()->where('project_tasks.id', $a->id)->exists());
    }

    public function test_a_transitive_circular_dependency_is_rejected(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);
        $a = $project->tasks()->create(['title' => 'Task A', 'status' => 'todo']);
        $b = $project->tasks()->create(['title' => 'Task B', 'status' => 'todo']);
        $c = $project->tasks()->create(['title' => 'Task C', 'status' => 'todo']);
        $a->dependencies()->attach($b->id); // A depends on B
        $b->dependencies()->attach($c->id); // B depends on C

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('addDependency', $c->id, $a->id); // C depends on A -- A -> B -> C -> A cycle

        $this->assertFalse($c->fresh()->dependencies()->where('project_tasks.id', $a->id)->exists());
    }

    public function test_a_task_from_another_project_cannot_be_added_as_a_dependency(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $projectA = $this->project($owner);
        $projectB = $this->project($owner);
        $taskA = $projectA->tasks()->create(['title' => 'Task A', 'status' => 'todo']);
        $taskB = $projectB->tasks()->create(['title' => 'Task B', 'status' => 'todo']);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $projectA])
            ->call('addDependency', $taskA->id, $taskB->id)
            ->assertForbidden();
    }

    public function test_an_outsider_cannot_even_open_the_board_to_manage_dependencies(): void
    {
        // ProjectBoard::mount() authorizes 'view' on the project before any
        // action method is reachable -- an outsider is rejected there, the
        // same boundary every other action on this board relies on.
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->create();
        $project = $this->project($owner);

        Livewire::actingAs($outsider)
            ->test(ProjectBoard::class, ['project' => $project])
            ->assertForbidden();
    }
}
