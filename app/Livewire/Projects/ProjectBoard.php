<?php

namespace App\Livewire\Projects;

use App\Events\DependencyBlocked;
use App\Events\ProjectBoardUpdated;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ProjectBoard extends Component
{
    public Project $project;

    public string $activeStatus = 'all';

    public ?int $movingTaskId = null;

    public bool $rejectingClosure = false;

    public string $closureRejectReason = '';

    public ?string $blockedMessage = null;

    public const COLUMNS = [
        'backlog' => 'Backlog',
        'todo' => 'To Do',
        'in_progress' => 'In Progress',
        'review' => 'Review',
        'done' => 'Done',
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
            ->with(['assignee', 'milestone', 'dependencies', 'timeLogs'])
            ->orderBy('sort_order')
            ->get()
            ->groupBy('status');

        $columns = [];
        foreach (array_keys(self::COLUMNS) as $status) {
            $columns[$status] = $tasks->get($status, collect());
        }

        return $columns;
    }

    /** All of this project's tasks, keyed by id -- used to populate the "add dependency" picker. */
    #[Computed]
    public function allTasks()
    {
        return $this->project->tasks()->orderBy('title')->get();
    }

    public function moveTask(int $taskId, string $newStatus): void
    {
        $this->authorize('update', $this->project);
        $this->blockedMessage = null;

        $task = ProjectTask::findOrFail($taskId);
        abort_unless($task->project_id === $this->project->id, 403);

        if ($newStatus === 'done') {
            $blockingTask = $task->dependencies()->where('status', '!=', 'done')->first();

            if ($blockingTask) {
                DependencyBlocked::dispatch($task, $blockingTask);
                $this->blockedMessage = "\"{$task->title}\" can't be marked done until \"{$blockingTask->title}\" is done first.";
                unset($this->tasksByStatus);

                return;
            }
        }

        $task->update(['status' => $newStatus]);

        if ($task->milestone_id) {
            $milestone = $task->milestone;

            if ($milestone && $milestone->syncStatusFromTasks()) {
                $this->project->closeIfAllMilestonesCompleted();
            }
        }

        broadcast(new ProjectBoardUpdated($this->project))->toOthers();
        unset($this->tasksByStatus);
    }

    /**
     * Add a "must be done first" dependency. Rejects a dependency that
     * would create a cycle (A depends on B, which already transitively
     * depends on A) rather than allowing an unsatisfiable graph.
     */
    public function addDependency(int $taskId, int $dependsOnTaskId): void
    {
        $this->authorize('update', $this->project);
        $this->blockedMessage = null;

        if ($taskId === $dependsOnTaskId) {
            return;
        }

        $task = ProjectTask::findOrFail($taskId);
        $dependsOn = ProjectTask::findOrFail($dependsOnTaskId);
        abort_unless($task->project_id === $this->project->id, 403);
        abort_unless($dependsOn->project_id === $this->project->id, 403);

        if ($this->wouldCreateCycle($dependsOn, $task)) {
            $this->blockedMessage = "That would create a circular dependency between \"{$task->title}\" and \"{$dependsOn->title}\".";

            return;
        }

        $task->dependencies()->syncWithoutDetaching([$dependsOn->id]);

        broadcast(new ProjectBoardUpdated($this->project))->toOthers();
        unset($this->tasksByStatus);
    }

    public function removeDependency(int $taskId, int $dependsOnTaskId): void
    {
        $this->authorize('update', $this->project);

        $task = ProjectTask::findOrFail($taskId);
        abort_unless($task->project_id === $this->project->id, 403);

        $task->dependencies()->detach($dependsOnTaskId);

        broadcast(new ProjectBoardUpdated($this->project))->toOthers();
        unset($this->tasksByStatus);
    }

    /**
     * True when $candidate already (transitively) depends on $task -- adding
     * "$task depends on $candidate" on top of that would make both
     * unsatisfiable. Walks the dependency graph breadth-first; project
     * boards are small enough that this is cheap.
     */
    private function wouldCreateCycle(ProjectTask $candidate, ProjectTask $task): bool
    {
        $visited = [];
        $queue = [$candidate->id];

        while ($queue) {
            $currentId = array_shift($queue);

            if ($currentId === $task->id) {
                return true;
            }

            if (in_array($currentId, $visited, true)) {
                continue;
            }
            $visited[] = $currentId;

            $queue = array_merge(
                $queue,
                ProjectTask::find($currentId)?->dependencies()->pluck('project_tasks.id')->all() ?? []
            );
        }

        return false;
    }

    public function confirmProjectClosure(): void
    {
        $this->authorize('closeProject', $this->project);

        $this->project->confirmClosure();
        $this->project->refresh();
    }

    public function promptRejectClosure(): void
    {
        $this->authorize('closeProject', $this->project);

        $this->rejectingClosure = true;
        $this->closureRejectReason = '';
    }

    public function cancelRejectClosure(): void
    {
        $this->rejectingClosure = false;
        $this->closureRejectReason = '';
    }

    public function confirmRejectClosure(): void
    {
        $this->authorize('closeProject', $this->project);

        if (trim($this->closureRejectReason) === '') {
            return;
        }

        $this->project->rejectClosure($this->closureRejectReason);
        $this->project->refresh();
        $this->rejectingClosure = false;
        $this->closureRejectReason = '';
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

        broadcast(new ProjectBoardUpdated($this->project))->toOthers();
        unset($this->tasksByStatus);
    }

    /** Log time spent on a task -- a lightweight manual entry, not a live timer. */
    public function logTime(int $taskId, int $minutes, string $note = ''): void
    {
        $this->authorize('update', $this->project);

        if ($minutes < 1) {
            return;
        }

        $task = ProjectTask::findOrFail($taskId);
        abort_unless($task->project_id === $this->project->id, 403);

        $task->timeLogs()->create([
            'user_id' => auth()->id(),
            'minutes' => $minutes,
            'note' => trim($note) ?: null,
            'logged_at' => now()->toDateString(),
        ]);

        broadcast(new ProjectBoardUpdated($this->project))->toOthers();
        unset($this->tasksByStatus);
    }

    /**
     * Called from the board view's Echo listener when another viewer's
     * action broadcasts a ProjectBoardUpdated event -- just invalidates the
     * cached computed properties so the next render re-fetches current state.
     */
    public function refreshBoard(): void
    {
        unset($this->tasksByStatus, $this->allTasks);
    }

    public function render(): View
    {
        return view('livewire.projects.project-board');
    }
}
