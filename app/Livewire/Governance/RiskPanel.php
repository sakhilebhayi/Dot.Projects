<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Models\ProjectRisk;
use App\Services\ActivityFeed;
use App\Services\Governance;
use App\Services\RiskMatrix;
use Illuminate\View\View;
use Livewire\Component;

/**
 * One risk: what it is, who owns it, its rating before and after mitigation, the plans, the next
 * review, escalation, and recording that it happened (which opens an issue). Saved with one button.
 */
class RiskPanel extends Component
{
    public Project $project;

    public ProjectRisk $risk;

    public string $title = '';

    public string $description = '';

    public string $category = 'other';

    public string $owner = '';

    public string $status = 'open';

    public string $probability = '';

    public string $impact = '';

    public string $residualProbability = '';

    public string $residualImpact = '';

    public string $mitigation = '';

    public string $contingency = '';

    public string $review = '';

    public string $escalationNote = '';

    public bool $confirmingOccurred = false;

    public function mount(Project $project, ProjectRisk $risk): void
    {
        abort_unless($risk->project_id === $project->id, 404);
        $this->authorize('view', $risk);
        $this->project = $project;
        $this->risk = $risk;
        $this->fillFromRisk();
    }

    private function fillFromRisk(): void
    {
        $r = $this->risk;
        $this->title = $r->title;
        $this->description = (string) $r->description;
        $this->category = $r->category;
        $this->owner = (string) $r->owner_id;
        $this->status = $r->status;
        $this->probability = (string) $r->probability;
        $this->impact = (string) $r->impact;
        $this->residualProbability = (string) $r->residual_probability;
        $this->residualImpact = (string) $r->residual_impact;
        $this->mitigation = (string) $r->mitigation;
        $this->contingency = (string) $r->contingency;
        $this->review = (string) $r->review_date?->toDateString();
    }

    public function save(Governance $gov): void
    {
        $this->authorize('update', $this->risk);
        $this->resetErrorBag();
        $n = fn (string $v) => $v === '' ? null : (int) $v;
        $t = fn (string $v) => trim($v) === '' ? null : trim($v);
        $gov->updateRisk($this->risk, auth()->user(), [
            'title' => trim($this->title),
            'description' => $t($this->description),
            'category' => $this->category,
            'owner_id' => $n($this->owner),
            'status' => $this->status,
            'probability' => $n($this->probability),
            'impact' => $n($this->impact),
            'residual_probability' => $n($this->residualProbability),
            'residual_impact' => $n($this->residualImpact),
            'mitigation' => $t($this->mitigation),
            'contingency' => $t($this->contingency),
            'review_date' => $t($this->review),
        ]);
        $this->risk->refresh();
        $this->fillFromRisk();
        session()->flash('saved', 'Saved.');
    }

    public function escalate(Governance $gov): void
    {
        $this->authorize('update', $this->risk);
        $this->resetErrorBag();
        $gov->escalate($this->risk, auth()->user(), $this->escalationNote);
        $this->reset('escalationNote');
        $this->risk->refresh();
    }

    public function withdraw(Governance $gov): void
    {
        $this->authorize('update', $this->risk);
        $gov->withdrawEscalation($this->risk);
        $this->risk->refresh();
    }

    public function occurred(Governance $gov): void
    {
        $this->authorize('update', $this->risk);
        $this->resetErrorBag();
        $issue = $gov->riskOccurred($this->risk, auth()->user());
        $this->redirectRoute('projects.issues.show', [$this->project, $issue], navigate: false);
    }

    public function render(): View
    {
        return view('livewire.governance.risk-panel', [
            'matrix' => RiskMatrix::forProject($this->project),
            'people' => $this->project->team->allUsers()->sortBy('name')->values(),
            'history' => ActivityFeed::forSubject($this->risk),
            'issues' => $this->risk->issues()->get(['id', 'number', 'title', 'status']),
            'canEdit' => auth()->user()->can('update', $this->risk),
        ]);
    }
}
