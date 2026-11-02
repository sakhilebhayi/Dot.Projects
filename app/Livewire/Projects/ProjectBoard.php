<?php

namespace App\Livewire\Projects;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Services\Activity;
use App\Services\ProjectWork;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * @property-read Collection<int, Milestone> $milestones
 */
class ProjectBoard extends Component
{
    public Project $project;

    public string $activeStatus = 'all';

    public ?int $movingTaskId = null;

    public bool $rejectingClosure = false;

    public string $closureRejectReason = '';

    // Quick add (R-34 P2): a task straight into Backlog, and a milestone.
    public string $newTaskTitle = '';

    public string $newTaskMilestone = '';

    public string $newTaskDue = '';

    public string $newMilestoneTitle = '';

    public string $newMilestoneDue = '';

    public const COLUMNS = [
        'backlog' => 'Backlog',
        'todo' => 'To do',
        'in_progress' => 'In progress',
        'review' => 'In review',
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
        // The people who can see the project (everyone in the business, or a private project's
        // own people), matching the check enforced server-side in assignTask().
        return $this->project->people();
    }

    #[Computed]
    public function tasksByStatus(): array
    {
        $tasks = $this->project->tasks()
            ->with(['assignee', 'milestone', 'predecessors:id,project_id,title,status', 'labels'])
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
        // Only the board's own columns: anything else from a tampered request is refused
        // before it reaches the database constraint.
        abort_unless(array_key_exists($newStatus, self::COLUMNS), 422, 'That is not a column on this board.');

        $task = ProjectTask::findOrFail($taskId);
        abort_unless($task->project_id === $this->project->id, 403);
        app(ProjectWork::class)->assertCanStart($task, $newStatus);

        $from = $task->status;
        $task->update(['status' => $newStatus, 'completed_at' => $newStatus === 'done' ? ($task->completed_at ?? now()) : null]);
        if ($from !== $newStatus) {
            Activity::record('task.moved', $task, ['status' => ['from' => $from, 'to' => $newStatus]], $this->project);
        }

        if ($task->milestone_id) {
            $milestone = $task->milestone;

            if ($milestone && $milestone->syncStatusFromTasks()) {
                $this->project->closeIfAllMilestonesCompleted();
            }
        }

        unset($this->tasksByStatus);
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
            abort_unless($assignee && $this->project->canBeSeenBy($assignee), 403);
        }

        $from = $task->assignee_id;
        $task->update(['assignee_id' => $assignee?->id]);
        if ($from !== $assignee?->id) {
            Activity::record('task.assigned', $task, ['assignee_id' => ['from' => $from, 'to' => $assignee?->id]], $this->project);
        }

        if ($assignee) {
            $assignee->notify(new TaskAssignedNotification($task));
        }

        unset($this->tasksByStatus);
    }

    #[Computed]
    public function milestones()
    {
        return $this->project->milestones()->get(['id', 'title', 'status', 'due_date']);
    }

    public function addTask(): void
    {
        $this->authorize('update', $this->project);
        $data = $this->validate([
            'newTaskTitle' => ['required', 'string', 'max:255'],
            'newTaskMilestone' => ['nullable', Rule::in($this->milestones->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'newTaskDue' => ['nullable', 'date'],
        ], [], ['newTaskTitle' => 'task', 'newTaskMilestone' => 'milestone', 'newTaskDue' => 'due date']);

        $task = DB::transaction(function () use ($data) {
            $task = ProjectTask::create([
                'project_id' => $this->project->id,
                'milestone_id' => $data['newTaskMilestone'] ?: null,
                'title' => trim($data['newTaskTitle']),
                'status' => 'backlog',
                'priority' => 'medium',
                'due_date' => $data['newTaskDue'] ?: null,
                'sort_order' => (int) $this->project->tasks()->where('status', 'backlog')->max('sort_order') + 1,
            ]);
            Activity::record('task.created', $task, [], $this->project);
            // A milestone that was complete is no longer complete with new work on it.
            $task->milestone?->syncStatusFromTasks();

            return $task;
        });

        $this->reset('newTaskTitle', 'newTaskDue');
        unset($this->tasksByStatus);
        $this->dispatch('task-added', id: $task->id);
    }

    public function addMilestone(): void
    {
        $this->authorize('update', $this->project);
        $data = $this->validate([
            'newMilestoneTitle' => ['required', 'string', 'max:255'],
            'newMilestoneDue' => ['nullable', 'date'],
        ], [], ['newMilestoneTitle' => 'milestone', 'newMilestoneDue' => 'due date']);

        $milestone = Milestone::create([
            'project_id' => $this->project->id,
            'title' => trim($data['newMilestoneTitle']),
            'due_date' => $data['newMilestoneDue'] ?: null,
            'status' => 'pending',
            'sort_order' => (int) $this->project->milestones()->max('sort_order') + 1,
        ]);
        Activity::record('milestone.created', $milestone, [], $this->project);

        $this->reset('newMilestoneTitle', 'newMilestoneDue');
        unset($this->milestones);
    }

    public function render(): View
    {
        return view('livewire.projects.project-board');
    }
}
