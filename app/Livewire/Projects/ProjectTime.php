<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\TimeReport;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The project's time for a period: totals and billable share, by task, by week, and (for those
 * who run the project) by person, with a CSV; and each task's time against its estimate to date.
 */
class ProjectTime extends Component
{
    public Project $project;

    #[Url(as: 'period', except: 'this_month')]
    public string $period = 'this_month';

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    #[Url(as: 'approved', except: false)]
    public bool $approved = false;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    public function render(): View
    {
        $report = app(TimeReport::class);
        $period = array_key_exists($this->period, TimeReport::PERIODS) ? $this->period : 'this_month';
        [$from, $to] = TimeReport::period($period, $this->from, $this->to, $this->project);
        $entries = $report->entries($this->project, $from, $to, $this->approved);

        return view('livewire.projects.project-time', [
            // Named apart from the $from and $to properties, which are the typed dates.
            'start' => $from,
            'end' => $to,
            's' => $report->summary($entries),
            'estimates' => $report->estimates($this->project),
            'detail' => auth()->user()->can('seeTimeDetail', $this->project),
            'csv' => route('projects.time.csv', array_filter(['project' => $this->project, 'period' => $period, 'from' => $period === 'custom' ? $from->toDateString() : null, 'to' => $period === 'custom' ? $to->toDateString() : null, 'approved' => $this->approved ? 1 : null])),
        ]);
    }
}
