<?php

namespace App\Livewire\Projects;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Services\Activity;
use App\Services\ProjectStatuses;
use App\Services\ProjectWork;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * @property-read Collection<int, Milestone> $milestones
 * @property-read \Illuminate\Support\Collection<int, array<string, mixed>> $columns
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

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> the board's columns: the project's statuses */
    #[Computed]
    public function columns()
    {
        return app(ProjectStatuses::class)->for($this->project);
    }

    #[Computed]
    public function tasksByStatus(): array
    {
        $statuses = $this->columns;
        $service = app(ProjectStatuses::class);
        $tasks = $this->project->tasks()
            ->with(['assignee', 'milestone', 'predecessors:id,project_id,title,status', 'labels'])
            ->orderBy('sort_order')
            ->get()
            ->groupBy(fn (ProjectTask $t) => $service->keyOf($t, $statuses));

        $columns = [];
        foreach ($statuses as $s) {
            $columns[$s['key']] = $tasks->get($s['key'], collect());
        }

        return $columns;
    }

    public function moveTask(int $taskId, string $newStatus): void
    {
        $this->authorize('update', $this->project);
        // Only the board's own columns: anything else from a tampered request is refused
        // before it reaches the database constraint.
        $to = app(ProjectStatuses::class)->resolve($this->project, $newStatus);
        abort_if($to === null || ! $this->columns->contains('key', $newStatus), 422, 'That is not a column on this board.');

        $task = ProjectTask::findOrFail($taskId);
        abort_unless($task->project_id === $this->project->id, 403);
        app(ProjectWork::class)->assertCanStart($task, $to['category']);

        $from = ['status' => $task->status, 'status_id' => $task->status_id];
        $task->update(['status' => $to['category'], 'status_id' => $to['id'], 'completed_at' => $to['category'] === 'done' ? ($task->completed_at ?? now()) : null]);
        if ($from['status'] !== $to['category'] || $from['status_id'] !== $to['id']) {
            Activity::record('task.moved', $task, ['status' => ['from' => $from['status'], 'to' => $to['category']], 'status_id' => ['from' => $from['status_id'], 'to' => $to['id']]], $this->project);
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

        $task = app(ProjectWork::class)->addTask($this->project, $data['newTaskTitle'], $data['newTaskMilestone'] ? (int) $data['newTaskMilestone'] : null, $data['newTaskDue'] ?: null);

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
