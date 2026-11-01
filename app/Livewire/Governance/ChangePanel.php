<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Models\ProjectChange;
use App\Services\ActivityFeed;
use App\Services\ChangeControl;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * One change request: what and why, the impact assessment, and its approval. A draft is edited and
 * sent to a named approver; the approver approves or rejects with a note; an approved change can
 * re-baseline the plan and be marked implemented.
 */
class ChangePanel extends Component
{
    public Project $project;

    public ProjectChange $change;

    public string $title = '';

    public string $kind = 'scope';

    public string $reason = '';

    public string $description = '';

    public string $scheduleDays = '';

    public string $cost = '';

    public string $scopeImpact = '';

    public string $resourceImpact = '';

    public string $riskImpact = '';

    public string $approver = '';

    public string $note = '';

    public function mount(Project $project, ProjectChange $change): void
    {
        abort_unless($change->project_id === $project->id, 404);
        $this->authorize('view', $change);
        $this->project = $project;
        $this->change = $change;
        $this->fillFromChange();
        $this->approver = (string) ($project->owner_id !== auth()->id() ? $project->owner_id : '');
    }

    private function fillFromChange(): void
    {
        $c = $this->change;
        $this->title = $c->title;
        $this->kind = $c->kind;
        $this->reason = (string) $c->reason;
        $this->description = (string) $c->description;
        $this->scheduleDays = $c->schedule_days === null ? '' : (string) $c->schedule_days;
        $this->cost = $c->cost_cents === null ? '' : rtrim(rtrim(number_format($c->cost_cents / 100, 2, '.', ''), '0'), '.');
        $this->scopeImpact = (string) $c->scope_impact;
        $this->resourceImpact = (string) $c->resource_impact;
        $this->riskImpact = (string) $c->risk_impact;
    }

    public function save(ChangeControl $cc): void
    {
        $this->authorize('update', $this->change);
        $this->resetErrorBag();
        $this->persist($cc);
        session()->flash('saved', 'Saved.');
    }

    private function persist(ChangeControl $cc): void
    {
        $cents = Money::parse($this->cost);
        if (trim($this->cost) !== '' && $cents === null) {
            throw ValidationException::withMessages(['cost' => 'Give the cost as an amount in rand, such as 45 000 or -12 500,50.']);
        }
        $t = fn (string $v) => trim($v) === '' ? null : trim($v);
        $cc->updateChange($this->change, [
            'title' => trim($this->title),
            'kind' => $this->kind,
            'reason' => $t($this->reason),
            'description' => $t($this->description),
            'schedule_days' => trim($this->scheduleDays) === '' ? null : (is_numeric($this->scheduleDays) ? (int) $this->scheduleDays : $this->scheduleDays),
            'cost_cents' => $cents,
            'scope_impact' => $t($this->scopeImpact),
            'resource_impact' => $t($this->resourceImpact),
            'risk_impact' => $t($this->riskImpact),
        ]);
        $this->change->refresh();
        $this->fillFromChange();
    }

    /** Saves the draft as it stands, then sends it to the chosen approver. */
    public function submit(ChangeControl $cc): void
    {
        $this->authorize('update', $this->change);
        $this->resetErrorBag();
        $this->persist($cc);
        $cc->submit($this->change, auth()->user(), $this->approver === '' ? null : (int) $this->approver);
        $this->change->refresh();
    }

    public function approve(ChangeControl $cc): void
    {
        $this->decide($cc, true);
    }

    public function reject(ChangeControl $cc): void
    {
        $this->decide($cc, false);
    }

    private function decide(ChangeControl $cc, bool $approve): void
    {
        $this->authorize('decide', $this->change);
        $this->resetErrorBag();
        $cc->decide($this->change, auth()->user(), $approve, $this->note);
        $this->reset('note');
        $this->change->refresh();
    }

    public function withdraw(ChangeControl $cc): void
    {
        $this->authorize('update', $this->change);
        $this->resetErrorBag();
        $cc->withdraw($this->change);
        $this->change->refresh();
    }

    public function rebaseline(ChangeControl $cc): void
    {
        $this->authorize('update', $this->change);
        $this->resetErrorBag();
        $cc->rebaseline($this->change, auth()->user());
        $this->change->refresh();
    }

    public function implement(ChangeControl $cc): void
    {
        $this->authorize('update', $this->change);
        $this->resetErrorBag();
        $cc->implement($this->change);
        $this->change->refresh();
    }

    public function render(): View
    {
        return view('livewire.governance.change-panel', [
            'approvers' => app(ChangeControl::class)->approvers($this->project),
            'history' => ActivityFeed::forSubject($this->change),
            'canEdit' => auth()->user()->can('update', $this->change),
            'canDecide' => auth()->user()->can('decide', $this->change),
        ]);
    }
}
