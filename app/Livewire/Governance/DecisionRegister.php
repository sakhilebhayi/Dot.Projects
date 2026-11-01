<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Models\ProjectDecision;
use App\Services\ChangeControl;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * @property-read Collection<int, ProjectDecision> $all
 * @property-read Collection<int, ProjectDecision> $decisions
 *
 * The decision register: what still needs deciding (with its owner and date) first, then every
 * decision made, newest first. A decision can be asked for or recorded as already made.
 */
class DecisionRegister extends Component
{
    public Project $project;

    #[Url(as: 'show', except: 'all')]
    public string $show = 'all';

    public string $title = '';

    public string $mode = 'ask';

    public string $owner = '';

    public string $decideBy = '';

    public string $outcome = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    #[Computed]
    public function all(): Collection
    {
        return $this->project->decisions()->with(['owner:id,name', 'decider:id,name', 'change:id,number,decision_id'])->get()
            ->sortBy([fn ($a, $b) => (int) ($b->status === 'proposed') <=> (int) ($a->status === 'proposed'), fn ($a, $b) => $b->number <=> $a->number])->values();
    }

    #[Computed]
    public function decisions(): Collection
    {
        return $this->all->filter(fn (ProjectDecision $d) => $this->show === 'all' || $d->status === $this->show)->values();
    }

    public function add(ChangeControl $cc): void
    {
        $this->authorize('update', $this->project);
        $this->resetErrorBag();
        $decided = $this->mode === 'record';
        $d = $cc->addDecision($this->project, auth()->user(), array_filter([
            'title' => trim($this->title),
            'owner_id' => $decided || $this->owner === '' ? null : (int) $this->owner,
            'decide_by' => $decided || $this->decideBy === '' ? null : $this->decideBy,
            'outcome' => $decided ? trim($this->outcome) : null,
        ], fn ($v) => $v !== null), $decided);
        $this->redirectRoute('projects.decisions.show', [$this->project, $d], navigate: false);
    }

    public function render(): View
    {
        return view('livewire.governance.decision-register', [
            'people' => $this->project->people(),
            'canEdit' => auth()->user()->can('update', $this->project),
        ]);
    }
}
