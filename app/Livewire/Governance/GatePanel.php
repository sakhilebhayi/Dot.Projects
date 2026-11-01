<?php

namespace App\Livewire\Governance;

use App\Models\GateCheck;
use App\Models\Project;
use App\Models\ProjectGate;
use App\Services\ActivityFeed;
use App\Services\ChangeControl;
use App\Services\Gates;
use Illuminate\View\View;
use Livewire\Component;

/**
 * One gate: what it is for, when, who decides; its governance checklist, ticked by the team; and
 * the approver's decision. Once passed, its record no longer changes.
 */
class GatePanel extends Component
{
    public Project $project;

    public ProjectGate $gate;

    public string $title = '';

    public string $purpose = '';

    public string $approver = '';

    public string $due = '';

    public string $milestone = '';

    public string $newCheck = '';

    public string $outcome = 'passed';

    public string $note = '';

    public function mount(Project $project, ProjectGate $gate): void
    {
        abort_unless($gate->project_id === $project->id, 404);
        $this->authorize('view', $gate);
        $this->project = $project;
        $this->gate = $gate;
        $this->fillFromGate();
        // A plain pass needs every check met, so start from the choice that is open.
        $this->outcome = $gate->checks()->whereNull('done_at')->exists() ? 'conditional' : 'passed';
    }

    private function fillFromGate(): void
    {
        $g = $this->gate;
        $this->title = $g->title;
        $this->purpose = (string) $g->purpose;
        $this->approver = (string) $g->approver_id;
        $this->due = (string) $g->due_date?->toDateString();
        $this->milestone = (string) $g->milestone_id;
    }

    public function save(Gates $gates): void
    {
        $this->authorize('update', $this->gate);
        $this->resetErrorBag();
        $gates->update($this->gate, auth()->user(), [
            'title' => trim($this->title),
            'purpose' => trim($this->purpose) === '' ? null : trim($this->purpose),
            'approver_id' => $this->approver === '' ? null : (int) $this->approver,
            'due_date' => $this->due === '' ? null : $this->due,
            'milestone_id' => $this->milestone === '' ? null : (int) $this->milestone,
        ]);
        $this->gate->refresh();
        $this->fillFromGate();
        session()->flash('saved', 'Saved.');
    }

    private function check(int $id): GateCheck
    {
        $check = $this->gate->checks()->find($id);
        abort_if($check === null, 404);

        return $check;
    }

    public function addCheck(Gates $gates): void
    {
        $this->authorize('update', $this->gate);
        $this->resetErrorBag();
        $gates->addCheck($this->gate, $this->newCheck);
        $this->reset('newCheck');
    }

    public function toggleCheck(int $id, Gates $gates): void
    {
        $this->authorize('update', $this->gate);
        $this->resetErrorBag();
        $gates->toggleCheck($this->check($id), auth()->user());
    }

    public function removeCheck(int $id, Gates $gates): void
    {
        $this->authorize('update', $this->gate);
        $this->resetErrorBag();
        $gates->removeCheck($this->check($id));
    }

    public function decide(Gates $gates): void
    {
        $this->authorize('decide', $this->gate);
        $this->resetErrorBag();
        $gates->decide($this->gate, auth()->user(), $this->outcome, $this->note);
        $this->reset('note');
        $this->gate->refresh();
    }

    public function reopen(Gates $gates): void
    {
        $this->authorize('update', $this->gate);
        $this->resetErrorBag();
        $gates->reopen($this->gate);
        $this->gate->refresh();
    }

    public function render(): View
    {
        return view('livewire.governance.gate-panel', [
            'checks' => $this->gate->checks()->with('doer:id,name')->get(),
            'approvers' => app(ChangeControl::class)->approvers($this->project),
            'milestones' => $this->project->milestones()->get(['id', 'title']),
            'history' => ActivityFeed::forSubject($this->gate),
            'canEdit' => auth()->user()->can('update', $this->gate),
            'canDecide' => auth()->user()->can('decide', $this->gate),
        ]);
    }
}
