<?php

namespace App\Livewire\Calendar;

use App\Livewire\Concerns\MonthCalendar;
use App\Models\ProjectTask;
use App\Services\BusinessCalendar;
use App\Services\ProjectWork;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The business calendar (R-34 P13d): one month across every open project the person can see, or one of them;
 * everything, one kind of event, or only their own. Open tasks in projects they may change move by drag or
 * arrow keys, exactly as on a project's own calendar.
 */
class Business extends Component
{
    use MonthCalendar;

    #[Url(as: 'project', except: '')]
    public string $projectId = '';

    /** Moves an open task, in a project the person can see and change, so it is due on $date. */
    public function moveTask(int $taskId, string $date, ProjectWork $work): void
    {
        $visible = app(BusinessCalendar::class)->projects()->pluck('id');
        $task = ProjectTask::query()->whereIn('project_id', $visible)->find($taskId);
        abort_if($task === null, 404);
        $this->moveTaskTo($task, $date, $work);
    }

    public function render(BusinessCalendar $calendar): View
    {
        $projects = $calendar->projects();
        $chosen = $this->projectId !== '' && $projects->contains('id', (int) $this->projectId) ? (int) $this->projectId : null;
        $first = $this->first();
        $gridStart = $first->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $first->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $events = $this->shown($calendar->events(auth()->user(), $gridStart, $gridEnd, $chosen));
        $month = collect($events)->filter(fn ($v, $d) => str_starts_with($d, $first->format('Y-m')))->flatten(1);

        return view('livewire.calendar.business', [
            'projects' => $projects,
            'chosen' => $chosen,
            'first' => $first,
            'gridStart' => $gridStart,
            'gridEnd' => $gridEnd,
            'events' => $events,
            'count' => $month->count(),
            'late' => $month->where('late', true)->count(),
            'across' => $month->pluck('project')->unique()->count(),
            'today' => Carbon::today()->toDateString(),
        ]);
    }
}
