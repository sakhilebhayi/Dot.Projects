<?php

namespace App\Livewire\Projects;

use App\Models\ChecklistItem;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\TimeEntry;
use App\Services\ProjectWork;
use App\Services\TimeTracking;
use App\Support\Duration;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * @property-read Collection<int, ProjectTask> $predecessors
 * @property-read Collection<int, ProjectTask> $successors
 * @property-read Collection<int, Project> $otherProjects
 * @property-read \Illuminate\Support\Collection<int, ProjectTask> $candidates
 * @property-read array<int, array{here: bool, project: string|null, url: string|null}> $places
 *
 * One task, read by every member and edited by people who can change the project: its details,
 * dates and estimate, who does it, its checklist, and deleting it. Saved with one button, so a
 * half-finished edit never changes the plan.
 */
class TaskPanel extends Component
{
    public Project $project;

    public ProjectTask $task;

    public string $title = '';

    public string $description = '';

    public string $status = 'backlog';

    public string $priority = 'medium';

    public string $assignee = '';

    public string $milestone = '';

    public string $startDate = '';

    public string $dueDate = '';

    public string $estimateHours = '';

    public string $newItem = '';

    public bool $confirmingDelete = false;

    public string $newDependency = '';

    /** The project to pick a predecessor from: '' for this one, or another project's id. */
    public string $depProject = '';

    public string $newSubtask = '';

    public string $newLabel = '';

    public string $logHours = '';

    public string $logNote = '';

    public bool $logBillable = false;

    public function mount(Project $project, ProjectTask $task): void
    {
        abort_unless($task->project_id === $project->id, 404);
        $this->authorize('view', $task);
        $this->project = $project;
        $this->task = $task;
        $this->fillFromTask();
    }

    private function fillFromTask(): void
    {
        $t = $this->task;
        $this->title = $t->title;
        $this->description = (string) $t->description;
        $this->status = $t->status ?? 'backlog';
        $this->priority = $t->priority ?? 'medium';
        $this->assignee = (string) $t->assignee_id;
        $this->milestone = (string) $t->milestone_id;
        $this->startDate = (string) $t->start_date?->toDateString();
        $this->dueDate = (string) $t->due_date?->toDateString();
        $this->estimateHours = $t->estimate_minutes === null ? '' : rtrim(rtrim(number_format($t->estimate_minutes / 60, 2, '.', ''), '0'), '.');
    }

    #[Computed]
    public function people()
    {
        return $this->project->people();
    }

    #[Computed]
    public function milestones()
    {
        return $this->project->milestones()->get(['id', 'title']);
    }

    #[Computed]
    public function checklist()
    {
        return $this->task->checklist()->get();
    }

    #[Computed]
    public function predecessors()
    {
        return $this->task->predecessors()->orderBy('title')->get();
    }

    #[Computed]
    public function successors()
    {
        return $this->task->successors()->orderBy('title')->get();
    }

    /**
     * Where each linked task lives, as this person may know it: here; in another project they can
     * see (named, with a link); or in one they cannot (not named, no link).
     *
     * @return array<int, array{here: bool, project: string|null, url: string|null}>
     */
    #[Computed]
    public function places(): array
    {
        $out = [];
        foreach ($this->predecessors->concat($this->successors) as $t) {
            $here = (int) $t->project_id === $this->project->id;
            $project = $here ? $this->project : $t->projectSeenBy(auth()->user());
            $out[$t->id] = ['here' => $here, 'project' => $project?->name, 'url' => $project === null ? null : route('projects.tasks.show', [$project, $t])];
        }

        return $out;
    }

    #[Computed]
    public function subtasks()
    {
        return $this->task->subtasks()->with('assignee')->get();
    }

    #[Computed]
    public function labels()
    {
        return $this->task->labels()->get();
    }

    /**
     * Other live projects in this business the person can see, whose tasks this one may wait
     * for. The scopes already hide other businesses and private projects they are not in.
     */
    #[Computed]
    public function otherProjects()
    {
        return Project::query()->whereKeyNot($this->project->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'team_id', 'visibility', 'owner_id']);
    }

    /** Tasks this one could wait for, in the chosen project (this one by default), not already linked. */
    #[Computed]
    public function candidates()
    {
        $linked = $this->predecessors->pluck('id')->push($this->task->id);
        $from = $this->pickFrom();

        return $from === null ? collect() : ProjectTask::query()->where('project_id', $from)->whereNotIn('id', $linked)->orderBy('title')->get(['id', 'title', 'status']);
    }

    /** The project id predecessors are picked from: this one, or a listed other one; null otherwise. */
    private function pickFrom(): ?int
    {
        return $this->depProject === '' ? $this->project->id : $this->otherProjects->firstWhere('id', (int) $this->depProject)?->id;
    }

    public function updatedDepProject(): void
    {
        $this->reset('newDependency');
        $this->resetErrorBag();
    }

    public function addDependency(ProjectWork $work): void
    {
        $this->authorize('update', $this->task);
        $this->resetErrorBag();
        $this->validate(['newDependency' => ['required', 'integer']], [], ['newDependency' => 'task']);
        $from = $this->pickFrom();
        abort_if($from === null, 404);
        $work->addDependency($this->task, ProjectTask::query()->where('project_id', $from)->findOrFail((int) $this->newDependency), auth()->user());
        $this->reset('newDependency');
        unset($this->predecessors, $this->candidates, $this->places);
    }

    public function removeDependency(int $id, ProjectWork $work): void
    {
        $this->authorize('update', $this->task);
        $work->removeDependency($this->task, $id);
        unset($this->predecessors, $this->candidates, $this->places);
    }

    public function addSubtask(ProjectWork $work): void
    {
        $this->authorize('update', $this->task);
        $this->validate(['newSubtask' => ['required', 'string', 'max:255']], [], ['newSubtask' => 'subtask']);
        $work->addSubtask($this->task, $this->newSubtask);
        $this->reset('newSubtask');
        unset($this->subtasks);
    }

    public function addLabel(ProjectWork $work): void
    {
        $this->authorize('update', $this->task);
        $work->addLabel($this->task, $this->newLabel);
        $this->reset('newLabel');
        unset($this->labels);
    }

    public function removeLabel(int $id, ProjectWork $work): void
    {
        $this->authorize('update', $this->task);
        $work->removeLabel($this->task, $id);
        unset($this->labels);
    }

    public function save(ProjectWork $work): void
    {
        $this->authorize('update', $this->task);
        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(ProjectWork::STATUSES)],
            'priority' => ['required', Rule::in(ProjectWork::PRIORITIES)],
            'assignee' => ['nullable', 'integer'],
            'milestone' => ['nullable', 'integer'],
            'startDate' => ['nullable', 'date'],
            'dueDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'estimateHours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ], ['dueDate.after_or_equal' => 'The due date cannot be before the start date.'], ['estimateHours' => 'estimate']);

        $work->updateTask($this->task, [
            'title' => trim($data['title']),
            'description' => trim((string) $data['description']) ?: null,
            'status' => $data['status'],
            'priority' => $data['priority'],
            'assignee_id' => $data['assignee'] ? (int) $data['assignee'] : null,
            'milestone_id' => $data['milestone'] ? (int) $data['milestone'] : null,
            'start_date' => $data['startDate'] ?: null,
            'due_date' => $data['dueDate'] ?: null,
            'estimate_minutes' => $data['estimateHours'] === '' || $data['estimateHours'] === null ? null : (int) round((float) $data['estimateHours'] * 60),
        ], auth()->user());

        $this->task->refresh();
        $this->fillFromTask();
        session()->flash('task-saved', 'Saved.');
    }

    public function addItem(ProjectWork $work): void
    {
        $this->authorize('update', $this->task);
        $this->validate(['newItem' => ['required', 'string', 'max:255']], [], ['newItem' => 'checklist item']);
        $work->addChecklistItem($this->task, $this->newItem);
        $this->reset('newItem');
        unset($this->checklist);
    }

    public function toggleItem(int $id, ProjectWork $work): void
    {
        $this->authorize('update', $this->task);
        $work->toggleChecklistItem($this->item($id), auth()->user());
        unset($this->checklist);
    }

    public function removeItem(int $id, ProjectWork $work): void
    {
        $this->authorize('update', $this->task);
        $work->removeChecklistItem($this->item($id));
        unset($this->checklist);
    }

    public function delete(ProjectWork $work): void
    {
        $this->authorize('delete', $this->task);
        $work->deleteTask($this->task);
        session()->flash('flash.banner', 'Task deleted.');
        $this->redirect(route('projects.work', $this->project));
    }

    private function item(int $id): ChecklistItem
    {
        return ChecklistItem::query()->where('project_task_id', $this->task->id)->findOrFail($id);
    }

    /**
     * Time on this task: everyone's total against the estimate, your own share, and the split by
     * person only for those who run the project (or own the business).
     *
     * @return array{total: int, mine: int, people: \Illuminate\Support\Collection<int, array{name: string, minutes: int}>|null}
     */
    #[Computed]
    public function time(): array
    {
        $entries = TimeEntry::query()->where('task_id', $this->task->id)->with('user:id,name')->get();
        $me = auth()->user();
        $seesPeople = $this->project->canBeManagedBy($me) || $me->ownsTeam($this->project->team);

        return [
            'total' => (int) $entries->sum(fn ($e) => $e->elapsed()),
            'mine' => (int) $entries->where('user_id', $me->id)->sum(fn ($e) => $e->elapsed()),
            'people' => $seesPeople ? $entries->groupBy('user_id')->map(fn ($g) => ['name' => (string) $g->first()->user->name, 'minutes' => (int) $g->sum(fn ($e) => $e->elapsed())])->sortByDesc('minutes')->values() : null,
        ];
    }

    public function logTime(TimeTracking $time): void
    {
        $this->resetErrorBag();
        $minutes = Duration::parse($this->logHours);
        if ($minutes === null) {
            throw ValidationException::withMessages(['minutes' => 'Give the time as hours and minutes, such as 1:30, 1.5 or 45m.']);
        }
        $time->log(auth()->user(), $this->project, ['task_id' => $this->task->id, 'work_date' => Carbon::today()->toDateString(), 'minutes' => $minutes, 'note' => $this->logNote, 'billable' => $this->logBillable]);
        $this->reset('logHours', 'logNote');
        unset($this->time);
    }

    public function startTimer(TimeTracking $time): void
    {
        $this->resetErrorBag();
        $time->start(auth()->user(), $this->project, $this->task->id, $this->logNote, $this->logBillable);
        $this->reset('logNote');
        unset($this->time);
    }

    public function stopTimer(TimeTracking $time): void
    {
        $time->stop(auth()->user());
        unset($this->time);
    }

    public function render(): View
    {
        return view('livewire.projects.task-panel', ['running' => app(TimeTracking::class)->running(auth()->user())]);
    }
}
