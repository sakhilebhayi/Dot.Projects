<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Services\ProjectSummary;
use App\Services\TimeReport;
use App\Services\TimeTracking;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A project's Time tab, and its entries as a CSV for those who run the project. */
class ProjectTimeController extends Controller
{
    public function show(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        return view('projects.time', ['project' => $project->load('owner'), 'summary' => new ProjectSummary($project)]);
    }

    public function csv(Request $request, Project $project, TimeReport $report): StreamedResponse
    {
        $this->authorize('seeTimeDetail', $project);
        [$from, $to] = TimeReport::period((string) $request->query('period', 'this_month'), $request->query('from'), $request->query('to'), $project);
        $approvedOnly = $request->boolean('approved');
        $entries = $report->entries($project, $from, $to, $approvedOnly);
        $sheets = Timesheet::query()->where('team_id', $project->team_id)->whereIn('user_id', $entries->pluck('user_id')->unique())->get()
            ->mapWithKeys(fn (Timesheet $s) => [$s->user_id.'|'.$s->week_start->toDateString() => $s->status]);
        $name = str($project->name)->slug().'-time-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($entries, $sheets) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Person', 'Task', 'Note', 'Hours', 'Minutes', 'Billable', 'Timesheet']);
            foreach ($entries as $e) {
                /** @var TimeEntry $e */
                $status = $sheets[$e->user_id.'|'.TimeTracking::weekStart($e->work_date)->toDateString()] ?? 'open';
                fputcsv($out, [$e->work_date->toDateString(), $e->user->name, $e->task->title ?? '', $this->cell((string) $e->note), number_format(((int) $e->minutes) / 60, 2, '.', ''), (int) $e->minutes, $e->billable ? 'yes' : 'no', Timesheet::STATUSES[$status] ?? $status]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Text a spreadsheet would read as a formula is written as plain text. */
    private function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
