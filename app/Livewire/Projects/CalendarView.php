<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\ProjectCalendar;
use App\Services\ProjectWork;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The project calendar (R-34 P13b): a month of deadlines, milestones, meetings, gates, actions and decisions, from
 * the same records every other view reads. Open tasks can be moved to another day by dragging, or with the arrow
 * keys; the move keeps the task's length, saves through the same path as the schedule and is in the activity history.
 */
class CalendarView extends Component
{
    public Project $project;

    #[Url(as: 'month', except: '')]
    public string $month = '';

    /** The day whose events are listed under the month (always on phones, where the grid shows only marks). */
    #[Url(as: 'day', except: '')]
    public string $day = '';

    #[Url(as: 'show', except: 'all')]
    public string $show = 'all';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    private function first(): Carbon
    {
        try {
            return $this->month !== '' ? Carbon::createFromFormat('!Y-m', $this->month)->startOfMonth() : Carbon::today()->startOfMonth();
        } catch (\Throwable) {
            return Carbon::today()->startOfMonth();
        }
    }

    public function go(int $months): void
    {
        $this->month = $months === 0 ? '' : $this->first()->addMonths($months)->format('Y-m');
        $this->day = $months === 0 ? Carbon::today()->toDateString() : '';
    }

    public function pick(string $date): void
    {
        $this->day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    }

    /** Moves an open task so it is due on $date, keeping its length. */
    public function moveTask(int $taskId, string $date, ProjectWork $work): void
    {
        $task = ProjectTask::query()->where('project_id', $this->project->id)->find($taskId);
        abort_if($task === null, 404);
        $this->authorize('update', $task);
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        if ($task->status === 'done') {
            $this->addError('calendar', 'Done work keeps the dates it was finished on.');

            return;
        }
        $due = Carbon::parse($date);
        $start = $task->start_date && $task->due_date ? $task->start_date->copy()->addDays((int) $task->due_date->diffInDays($due, false)) : null;
        $work->reschedule($task, $start?->toDateString(), $due->toDateString());
        $this->day = $due->toDateString();
    }

    public function render(ProjectCalendar $calendar): View
    {
        $first = $this->first();
        $gridStart = $first->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $first->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $events = $calendar->events($this->project, $gridStart, $gridEnd, auth()->user());
        if ($this->show !== 'all') {
            $events = array_filter(array_map(fn ($day) => array_values(array_filter($day, fn ($e) => $this->show === 'mine' ? $e['mine'] : $e['kind'] === $this->show)), $events));
        }
        $count = collect($events)->filter(fn ($v, $d) => str_starts_with($d, $first->format('Y-m')))->flatten(1)->count();

        return view('livewire.projects.calendar-view', [
            'first' => $first,
            'gridStart' => $gridStart,
            'gridEnd' => $gridEnd,
            'events' => $events,
            'count' => $count,
            'today' => Carbon::today()->toDateString(),
        ]);
    }
}
