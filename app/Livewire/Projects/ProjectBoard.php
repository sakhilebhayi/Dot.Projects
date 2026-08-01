<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ProjectBoard extends Component
{
    public Project $project;

    public string $activeStatus = 'all';

    public ?int $movingTaskId = null;

    public const COLUMNS = [
        'backlog'     => 'Backlog',
        'todo'        => 'To Do',
        'in_progress' => 'In Progress',
        'review'      => 'Review',
        'done'        => 'Done',
    ];

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
    }

    #[Computed]
    public function assignableUsers()
    {
        // Assignment is scoped to the project's team (not the separate `project_members`
        // pivot, which has no UI to populate it yet) — matches the team-membership check
        // enforced server-side in assignTask().
        return $this->project->team->allUsers();
    }

    #[Computed]
    public function tasksByStatus(): array
    {
        $tasks = $this->project->tasks()
            ->with(['assignee', 'milestone'])
            ->orderBy('sort_order')
            ->get()
            ->groupBy('status');

        $columns = [];
        foreach (array_keys(self::COLUMNS) as $status) {
            $columns[$status] = $tasks->get($status, collect());
        }

        return $columns;
    }

    public function moveTask(int $taskId, string $newStatus): void
    {
        $this->authorize('update', $this->project);

        $task = ProjectTask::findOrFail($taskId);
        abort_unless($task->project_id === $this->project->id, 403);

        $task->update(['status' => $newStatus]);

        if ($task->milestone_id) {
            $milestone = $task->milestone;

            if ($milestone && $milestone->syncStatusFromTasks()) {
                $this->project->closeIfAllMilestonesCompleted();
            }
        }

        unset($this->tasksByStatus);
    }

    public function assignTask(int $taskId, ?int $userId): void
    {
        $this->authorize('update', $this->project);

        $task = ProjectTask::findOrFail($taskId);
        abort_unless($task->project_id === $this->project->id, 403);

        $assignee = null;

        if ($userId) {
            // Only allow assigning to someone who actually belongs to the project's team,
            // regardless of what a tampered client sends.
            $assignee = User::find($userId);
            abort_unless($assignee && $assignee->belongsToTeam($this->project->team), 403);
        }

        $task->update(['assignee_id' => $assignee?->id]);

        if ($assignee) {
            $assignee->notify(new TaskAssignedNotification($task));
        }

        unset($this->tasksByStatus);
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.projects.project-board');
    }
}
