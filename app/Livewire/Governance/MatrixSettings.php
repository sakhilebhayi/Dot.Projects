<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Services\Governance;
use App\Services\RiskMatrix;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The project's risk matrix on the settings page: 3, 4 or 5 steps, the name of each step, and the
 * score at which a risk becomes medium, high and critical. The preview recolours as bands change.
 */
class MatrixSettings extends Component
{
    public Project $project;

    public int $size = 5;

    /** @var list<string> */
    public array $probability = [];

    /** @var list<string> */
    public array $impact = [];

    public int $medium = 5;

    public int $high = 10;

    public int $critical = 15;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
        $this->load(RiskMatrix::forProject($project));
    }

    private function load(RiskMatrix $m): void
    {
        $this->size = $m->size;
        $this->probability = $m->probability;
        $this->impact = $m->impact;
        ['medium' => $this->medium, 'high' => $this->high, 'critical' => $this->critical] = $m->bands;
    }

    /** Switching size starts from that size's standard names and bands. */
    public function updatedSize(): void
    {
        $this->size = in_array((int) $this->size, [3, 4, 5], true) ? (int) $this->size : 5;
        $this->load(RiskMatrix::default($this->size));
        $this->resetErrorBag();
    }

    public function save(Governance $gov): void
    {
        $this->authorize('update', $this->project);
        $this->resetErrorBag();
        $gov->setMatrix($this->project, ['size' => $this->size, 'probability' => $this->probability, 'impact' => $this->impact, 'bands' => ['medium' => $this->medium, 'high' => $this->high, 'critical' => $this->critical]]);
        session()->flash('matrix-saved', 'Saved. Every rating on this project now reads against this matrix.');
    }

    public function render(): View
    {
        $preview = null;
        try {
            $fill = fn (array $labels) => array_map(fn ($l) => trim((string) $l) === '' ? '?' : (string) $l, array_pad($labels, $this->size, '?'));
            $preview = RiskMatrix::fromArray(['size' => $this->size, 'probability' => $fill($this->probability), 'impact' => $fill($this->impact), 'bands' => ['medium' => $this->medium, 'high' => $this->high, 'critical' => $this->critical]]);
        } catch (ValidationException) {
            // The preview waits until the bands make sense; saving reports the problem.
        }

        return view('livewire.governance.matrix-settings', ['preview' => $preview, 'canEdit' => auth()->user()->can('update', $this->project)]);
    }
}
