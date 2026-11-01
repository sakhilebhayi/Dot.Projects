<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Services\StatusReports;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Every status report for the project, newest first, with its period and rating; the draft in
 * progress (one at a time) is reached from here.
 */
class ReportList extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    public function start(StatusReports $reports): void
    {
        $this->authorize('update', $this->project);
        $r = $reports->start($this->project, auth()->user());
        $this->redirectRoute('projects.reports.show', [$this->project, $r], navigate: false);
    }

    public function render(): View
    {
        return view('livewire.governance.report-list', [
            'reports' => $this->project->reports()->with('publisher:id,name')->orderByDesc('number')->get(),
            'canEdit' => auth()->user()->can('update', $this->project),
        ]);
    }
}
