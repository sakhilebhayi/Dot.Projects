<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Models\ProjectAction;
use App\Services\Meetings;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * @property-read Collection<int, ProjectAction> $all
 * @property-read Collection<int, ProjectAction> $actions
 *
 * The action register: every action agreed, open ones first by due date, late ones flagged, with
 * the meeting it came from. Ticked off in place; owner and date changed in place.
 */
class ActionRegister extends Component
{
    public Project $project;

    #[Url(as: 'show', except: 'open')]
    public string $show = 'open';

    #[Url(as: 'who', except: '')]
    public string $who = '';

    public string $title = '';

    public string $owner = '';

    public string $due = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    #[Computed]
    public function all(): Collection
    {
        return $this->project->actions()->with(['owner:id,name', 'meeting:id,number,title'])->get()
            ->sortBy([fn ($a, $b) => (int) ($b->status === 'open') <=> (int) ($a->status === 'open'), fn ($a, $b) => ($a->due_date->timestamp ?? PHP_INT_MAX) <=> ($b->due_date->timestamp ?? PHP_INT_MAX), fn ($a, $b) => $a->number <=> $b->number])->values();
    }

    #[Computed]
    public function actions(): Collection
    {
        return $this->all
            ->filter(fn (ProjectAction $a) => $this->show === 'all' || $a->status === $this->show)
            ->filter(fn (ProjectAction $a) => $this->who === '' || (string) $a->owner_id === $this->who)
            ->values();
    }

    private function find(int $id): ProjectAction
    {
        $a = ProjectAction::query()->where('project_id', $this->project->id)->find($id);
        abort_if($a === null, 404);

        return $a;
    }

    public function add(Meetings $meetings): void
    {
        $this->authorize('update', $this->project);
        $this->resetErrorBag();
        $meetings->addAction($this->project, auth()->user(), [
            'title' => trim($this->title),
            'owner_id' => $this->owner === '' ? null : (int) $this->owner,
            'due_date' => $this->due === '' ? null : $this->due,
        ]);
        $this->reset('title', 'owner', 'due');
    }

    public function toggle(int $id, Meetings $meetings): void
    {
        $this->authorize('update', $this->project);
        $meetings->toggleAction($this->find($id));
    }

    public function setOwner(int $id, ?string $owner, Meetings $meetings): void
    {
        $this->authorize('update', $this->project);
        $this->resetErrorBag();
        $meetings->updateAction($this->find($id), ['owner_id' => $owner === null || $owner === '' ? null : (int) $owner]);
    }

    public function setDue(int $id, ?string $due, Meetings $meetings): void
    {
        $this->authorize('update', $this->project);
        $this->resetErrorBag();
        $meetings->updateAction($this->find($id), ['due_date' => $due === null || $due === '' ? null : $due]);
    }

    public function render(): View
    {
        return view('livewire.governance.action-register', [
            'people' => $this->project->people(),
            'canEdit' => auth()->user()->can('update', $this->project),
        ]);
    }
}
