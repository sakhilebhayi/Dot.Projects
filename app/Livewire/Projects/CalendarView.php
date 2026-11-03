<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\MonthCalendar;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\ProjectCalendar;
use App\Services\ProjectWork;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The project calendar (R-34 P13b): a month of deadlines, milestones, meetings, gates, actions and decisions, from
 * the same records every other view reads. Open tasks can be moved to another day by dragging, or with the arrow
 * keys; the move keeps the task's length, saves through the same path as the schedule and is in the activity history.
 */
class CalendarView extends Component
{
    use MonthCalendar;

    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    /** Moves an open task so it is due on $date, keeping its length. */
    public function moveTask(int $taskId, string $date, ProjectWork $work): void
    {
        $task = ProjectTask::query()->where('project_id', $this->project->id)->find($taskId);
        abort_if($task === null, 404);
        $this->moveTaskTo($task, $date, $work);
    }

    public function render(ProjectCalendar $calendar): View
    {
        $first = $this->first();
        $gridStart = $first->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $first->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $events = $calendar->events($this->project, $gridStart, $gridEnd, auth()->user());
        $events = $this->shown($events);
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
