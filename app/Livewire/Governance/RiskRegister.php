<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Models\ProjectRisk;
use App\Services\Governance;
use App\Services\RiskMatrix;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * @property-read RiskMatrix $matrix
 * @property-read Collection<int, ProjectRisk> $all
 * @property-read Collection<int, ProjectRisk> $risks
 *
 * The risk register: the matrix as a heat grid of live risks (click a cell to filter), what needs
 * attention, and the register itself ordered by rating. Adding a risk asks only for what rates it;
 * the rest is filled in on the risk's own page.
 */
class RiskRegister extends Component
{
    public Project $project;

    #[Url(as: 'show', except: 'live')]
    public string $show = 'live';

    #[Url(as: 'cell', except: '')]
    public string $cell = '';

    #[Url(as: 'rating', except: 'current')]
    public string $rating = 'current';

    public string $title = '';

    public string $category = 'other';

    public string $owner = '';

    public string $probability = '';

    public string $impact = '';

    public string $review = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    #[Computed]
    public function matrix(): RiskMatrix
    {
        return RiskMatrix::forProject($this->project);
    }

    #[Computed]
    public function all(): Collection
    {
        return $this->project->risks()->with(['owner:id,name', 'escalator:id,name'])->get()
            ->sortBy([fn ($a, $b) => $b->currentScore() <=> $a->currentScore(), fn ($a, $b) => $a->number <=> $b->number])->values();
    }

    /** The rating a cell counts: after mitigation where rated, or before it on request. */
    public function at(ProjectRisk $r): string
    {
        return $this->rating === 'inherent' || $r->residualScore() === null ? $r->probability.'-'.$r->impact : $r->residual_probability.'-'.$r->residual_impact;
    }

    #[Computed]
    public function risks(): Collection
    {
        return $this->all
            ->filter(fn (ProjectRisk $r) => match ($this->show) {
                'closed' => ! $r->isLive(), 'all' => true, default => $r->isLive()
            })
            ->filter(fn (ProjectRisk $r) => $this->cell === '' || ($r->isLive() && $this->at($r) === $this->cell))
            ->values();
    }

    public function pick(string $cell): void
    {
        $this->cell = $this->cell === $cell ? '' : $cell;
        $this->show = 'live';
    }

    public function add(Governance $gov): void
    {
        $this->authorize('update', $this->project);
        $this->resetErrorBag();
        $risk = $gov->addRisk($this->project, auth()->user(), [
            'title' => trim($this->title),
            'category' => $this->category,
            'owner_id' => $this->owner === '' ? null : (int) $this->owner,
            'probability' => $this->probability === '' ? null : (int) $this->probability,
            'impact' => $this->impact === '' ? null : (int) $this->impact,
            'review_date' => $this->review === '' ? null : $this->review,
        ]);
        $this->redirectRoute('projects.risks.show', [$this->project, $risk], navigate: false);
    }

    public function render(): View
    {
        return view('livewire.governance.risk-register', [
            'people' => $this->project->team->allUsers()->sortBy('name')->values(),
            'canEdit' => auth()->user()->can('update', $this->project),
        ]);
    }
}
