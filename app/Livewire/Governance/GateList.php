<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Models\ProjectGate;
use App\Services\ChangeControl;
use App\Services\Gates;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * @property-read Collection<int, ProjectGate> $gates
 *
 * The project's stage gates in order, each with how much of its checklist is met, who decides it
 * and when it is due. A gate can start from a standard checklist.
 */
class GateList extends Component
{
    public Project $project;

    public string $template = 'execute';

    public string $title = '';

    public string $approver = '';

    public string $due = '';

    public string $milestone = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
        $this->title = Gates::TEMPLATES[$this->template]['title'];
        $this->approver = (string) $project->owner_id;
    }

    public function updatedTemplate(): void
    {
        if (isset(Gates::TEMPLATES[$this->template])) {
            $this->title = Gates::TEMPLATES[$this->template]['title'];
        }
    }

    #[Computed]
    public function gates(): Collection
    {
        return $this->project->gates()->with(['approver:id,name', 'milestone:id,title,due_date'])->withCount(['checks', 'checks as checks_done_count' => fn ($q) => $q->whereNotNull('done_at')])->get();
    }

    public function add(Gates $gates): void
    {
        $this->authorize('update', $this->project);
        $this->resetErrorBag();
        $gate = $gates->add($this->project, auth()->user(), [
            'title' => trim($this->title),
            'approver_id' => $this->approver === '' ? null : (int) $this->approver,
            'due_date' => $this->due === '' ? null : $this->due,
            'milestone_id' => $this->milestone === '' ? null : (int) $this->milestone,
        ], $this->template === 'blank' ? null : $this->template);
        $this->redirectRoute('projects.gates.show', [$this->project, $gate], navigate: false);
    }

    public function render(): View
    {
        return view('livewire.governance.gate-list', [
            'approvers' => app(ChangeControl::class)->approvers($this->project),
            'milestones' => $this->project->milestones()->get(['id', 'title']),
            'canEdit' => auth()->user()->can('update', $this->project),
        ]);
    }
}
