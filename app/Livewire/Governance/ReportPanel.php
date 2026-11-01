<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Models\ProjectReport;
use App\Services\StatusReports;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Component;

/**
 * One status report. A draft shows the live facts and a suggested rating beside the manager's
 * words; publishing freezes the facts. A published report reads as a document and prints cleanly.
 */
class ReportPanel extends Component
{
    public Project $project;

    public ProjectReport $report;

    public string $from = '';

    public string $to = '';

    public string $overall = '';

    public string $summary = '';

    public string $achievements = '';

    public string $nextSteps = '';

    public string $helpNeeded = '';

    public bool $confirmingDiscard = false;

    public function mount(Project $project, ProjectReport $report): void
    {
        abort_unless($report->project_id === $project->id, 404);
        $this->authorize('view', $report);
        $this->project = $project;
        $this->report = $report;
        $this->fillFromReport();
    }

    private function fillFromReport(): void
    {
        $r = $this->report;
        $this->from = $r->period_from->toDateString();
        $this->to = $r->period_to->toDateString();
        $this->overall = (string) $r->overall;
        $this->summary = (string) $r->summary;
        $this->achievements = (string) $r->achievements;
        $this->nextSteps = (string) $r->next_steps;
        $this->helpNeeded = (string) $r->help_needed;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $t = fn (string $v) => trim($v) === '' ? null : trim($v);

        return [
            'period_from' => $this->from, 'period_to' => $this->to, 'overall' => $this->overall === '' ? null : $this->overall,
            'summary' => $t($this->summary), 'achievements' => $t($this->achievements), 'next_steps' => $t($this->nextSteps), 'help_needed' => $t($this->helpNeeded),
        ];
    }

    public function save(StatusReports $reports): void
    {
        $this->authorize('update', $this->report);
        $this->resetErrorBag();
        $reports->update($this->report, $this->data());
        $this->report->refresh();
        $this->fillFromReport();
        session()->flash('saved', 'Draft saved.');
    }

    public function publish(StatusReports $reports): void
    {
        $this->authorize('update', $this->report);
        $this->resetErrorBag();
        $reports->update($this->report, $this->data());
        $reports->publish($this->report->refresh(), auth()->user());
        $this->report->refresh();
    }

    public function discard(StatusReports $reports): void
    {
        $this->authorize('update', $this->report);
        $reports->discard($this->report);
        $this->redirectRoute('projects.reports', $this->project, navigate: false);
    }

    /** A typed date when it is one, else the period as saved. */
    private function date(string $typed, Carbon $saved): Carbon
    {
        try {
            return $typed === '' ? $saved : Carbon::parse($typed);
        } catch (\Throwable) {
            return $saved;
        }
    }

    public function render(): View
    {
        $reports = app(StatusReports::class);
        $facts = $this->report->published()
            ? (array) $this->report->snapshot
            : $reports->facts($this->project, $this->date($this->from, $this->report->period_from), $this->date($this->to, $this->report->period_to));

        return view('livewire.governance.report-panel', [
            'f' => $facts,
            'suggestion' => $this->report->published() ? ($facts['suggestion'] ?? null) : $reports->suggest($facts),
            'canEdit' => auth()->user()->can('update', $this->report),
        ]);
    }
}
