<?php

namespace App\Livewire\Projects;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\SavedView;
use App\Services\ProjectStatuses;
use App\Services\ProjectWork;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The project's work as a table, grouped by milestone: filter by status, person or lateness,
 * change status and assignee in place, and manage the milestones themselves (rename, date,
 * reorder, delete). The filters live in the URL, so a filtered view can be bookmarked or shared.
 *
 * @property-read Collection<int, Milestone> $milestones
 */
class WorkList extends Component
{
    public Project $project;

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'who', except: '')]
    public string $who = '';

    #[Url(as: 'late', except: false)]
    public bool $lateOnly = false;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'label', except: '')]
    public string $label = '';

    public string $viewName = '';

    public ?int $editingMilestone = null;

    public string $milestoneTitle = '';

    public string $milestoneDue = '';

    public ?int $deletingMilestone = null;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    #[Computed]
    public function milestones()
    {
        return $this->project->milestones()->get();
    }

    #[Computed]
    public function people()
    {
        return $this->project->people();
    }

    #[Computed]
    public function tasks()
    {
        $today = Carbon::today()->toDateString();

        return $this->project->tasks()->with(['assignee', 'checklist', 'labels', 'parent:id,title', 'predecessors:id,project_id,title,status'])
            ->when($this->label !== '', fn ($q) => $q->whereHas('labels', fn ($l) => $l->whereKey((int) $this->label)))
            ->when($this->status !== '', function ($q) {
                if ($this->status === 'open') {
                    return $q->where('status', '!=', 'done');
                }
                // One of the project's own statuses, or a category (also how saved views from before P13c read).
                $statuses = app(ProjectStatuses::class);
                $to = $statuses->resolve($this->project, $this->status);
                if ($to === null) {
                    return $q->whereRaw('1 = 0');
                }
                if ($to['id'] === null || $this->status === $to['category']) {
                    return $q->where('status', $to['category']);
                }
                // A task with no status of its own shows under the first status of its category, so it filters there too.
                $first = $statuses->for($this->project)->firstWhere('category', $to['category'])['id'] ?? null;

                return $q->where(fn ($w) => $w->where('status_id', $to['id'])
                    ->when($first === $to['id'], fn ($o) => $o->orWhere(fn ($n) => $n->whereNull('status_id')->where('status', $to['category']))));
            })
            ->when($this->who !== '', fn ($q) => $this->who === 'none' ? $q->whereNull('assignee_id') : $q->where('assignee_id', (int) $this->who))
            ->when($this->lateOnly, fn ($q) => $q->where('status', '!=', 'done')->whereNotNull('due_date')->whereDate('due_date', '<', $today))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('title', 'like', '%'.trim($this->search).'%'))
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('sort_order')
            ->get();
    }

    public function filtered(): bool
    {
        return $this->status !== '' || $this->who !== '' || $this->lateOnly || trim($this->search) !== '' || $this->label !== '';
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'who', 'lateOnly', 'search', 'label');
    }

    #[Computed]
    public function labels()
    {
        return $this->project->labels()->get();
    }

    /** The signed-in person's saved views of this project. */
    #[Computed]
    public function views()
    {
        return SavedView::query()->where('user_id', auth()->id())->where('project_id', $this->project->id)->orderBy('name')->get();
    }

    public function saveView(ProjectWork $work): void
    {
        $this->authorize('view', $this->project);
        $this->validate(['viewName' => ['required', 'string', 'max:60']], [], ['viewName' => 'name']);
        $work->saveView(auth()->user(), $this->project, $this->viewName, ['status' => $this->status, 'who' => $this->who, 'late' => $this->lateOnly, 'q' => trim($this->search), 'label' => $this->label]);
        $this->reset('viewName');
        unset($this->views);
    }

    public function applyView(int $id): void
    {
        // Someone else's view, or another project's, is simply not there.
        $view = SavedView::query()->where('user_id', auth()->id())->where('project_id', $this->project->id)->find($id);
        abort_if($view === null, 404);
        $f = $view->filters;
        $this->status = (string) ($f['status'] ?? '');
        $this->who = (string) ($f['who'] ?? '');
        $this->lateOnly = (bool) ($f['late'] ?? false);
        $this->search = (string) ($f['q'] ?? '');
        $this->label = (string) ($f['label'] ?? '');
    }

    public function deleteView(int $id): void
    {
        SavedView::query()->where('user_id', auth()->id())->where('project_id', $this->project->id)->whereKey($id)->delete();
        unset($this->views);
    }

    public function setStatus(int $taskId, string $status, ProjectWork $work): void
    {
        $task = $this->task($taskId);
        $this->authorize('update', $task);
        abort_unless(app(ProjectStatuses::class)->resolve($this->project, $status) !== null, 422);
        $work->updateTask($task, ['status' => $status], auth()->user());
        unset($this->tasks, $this->milestones);
    }

    public function setAssignee(int $taskId, ?string $userId, ProjectWork $work): void
    {
        $task = $this->task($taskId);
        $this->authorize('update', $task);
        $work->updateTask($task, ['assignee_id' => $userId ? (int) $userId : null], auth()->user());
        unset($this->tasks);
    }

    public function editMilestone(int $id): void
    {
        $m = $this->milestone($id);
        $this->authorize('update', $m);
        $this->editingMilestone = $m->id;
        $this->milestoneTitle = $m->title;
        $this->milestoneDue = (string) $m->due_date?->toDateString();
    }

    public function saveMilestone(ProjectWork $work): void
    {
        $m = $this->milestone((int) $this->editingMilestone);
        $this->authorize('update', $m);
        $data = $this->validate(['milestoneTitle' => ['required', 'string', 'max:255'], 'milestoneDue' => ['nullable', 'date']], [], ['milestoneTitle' => 'milestone', 'milestoneDue' => 'due date']);
        $work->updateMilestone($m, ['title' => $data['milestoneTitle'], 'due_date' => $data['milestoneDue'] ?: null]);
        $this->reset('editingMilestone', 'milestoneTitle', 'milestoneDue');
        unset($this->milestones);
    }

    public function moveMilestone(int $id, string $direction, ProjectWork $work): void
    {
        $m = $this->milestone($id);
        $this->authorize('update', $m);
        abort_unless(in_array($direction, ['up', 'down'], true), 422);
        $work->moveMilestone($m, $direction);
        unset($this->milestones);
    }

    public function deleteMilestone(ProjectWork $work): void
    {
        $m = $this->milestone((int) $this->deletingMilestone);
        $this->authorize('delete', $m);
        $work->deleteMilestone($m);
        $this->reset('deletingMilestone');
        unset($this->milestones, $this->tasks);
    }

    private function task(int $id): ProjectTask
    {
        return ProjectTask::query()->where('project_id', $this->project->id)->findOrFail($id);
    }

    private function milestone(int $id): Milestone
    {
        return Milestone::query()->where('project_id', $this->project->id)->findOrFail($id);
    }

    public function render(): View
    {
        return view('livewire.projects.work-list', [
            'statuses' => app(ProjectStatuses::class)->for($this->project),
            'canEdit' => auth()->user()->can('update', $this->project),
            'today' => Carbon::today(),
        ]);
    }
}
