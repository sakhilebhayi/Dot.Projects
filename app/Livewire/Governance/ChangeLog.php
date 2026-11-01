<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Models\ProjectChange;
use App\Services\ChangeControl;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * @property-read Collection<int, ProjectChange> $all
 * @property-read Collection<int, ProjectChange> $changes
 *
 * The change log: every change request with its effect on time and money and where it stands in
 * approval, and what approved changes add up to. Raising one asks for a title and a kind; the
 * impact assessment is filled in on its own page before it goes for approval.
 */
class ChangeLog extends Component
{
    public Project $project;

    #[Url(as: 'show', except: 'all')]
    public string $show = 'all';

    public string $title = '';

    public string $kind = 'scope';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    #[Computed]
    public function all(): Collection
    {
        return $this->project->changes()->with(['requester:id,name', 'approver:id,name'])->orderByDesc('number')->get();
    }

    #[Computed]
    public function changes(): Collection
    {
        return $this->all->filter(fn (ProjectChange $c) => match ($this->show) {
            'open' => in_array($c->status, ['draft', 'submitted'], true),
            'accepted' => $c->accepted(),
            'closed' => in_array($c->status, ['rejected', 'withdrawn'], true),
            default => true,
        })->values();
    }

    public function add(ChangeControl $cc): void
    {
        $this->authorize('update', $this->project);
        $this->resetErrorBag();
        $change = $cc->addChange($this->project, auth()->user(), ['title' => trim($this->title), 'kind' => $this->kind]);
        $this->redirectRoute('projects.changes.show', [$this->project, $change], navigate: false);
    }

    public function render(): View
    {
        return view('livewire.governance.change-log', ['canEdit' => auth()->user()->can('update', $this->project)]);
    }
}
