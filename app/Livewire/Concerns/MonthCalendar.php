<?php

namespace App\Livewire\Concerns;

use App\Models\ProjectTask;
use App\Services\ProjectWork;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * The month both calendars show (R-34 P13b and P13d): which month, which day is listed under it, and moving an
 * open task to another day with its length kept, through the schedule's reschedule path.
 */
trait MonthCalendar
{
    #[Url(as: 'month', except: '')]
    public string $month = '';

    /** The day whose events are listed under the month (always on phones, where the grid shows only marks). */
    #[Url(as: 'day', except: '')]
    public string $day = '';

    #[Url(as: 'show', except: 'all')]
    public string $show = 'all';

    protected function first(): Carbon
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

    /** Moves an open task the person may change so it is due on $date, keeping its length. */
    protected function moveTaskTo(ProjectTask $task, string $date, ProjectWork $work): void
    {
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

    /**
     * @param  array<string, list<array<string, mixed>>>  $events
     * @return array<string, list<array<string, mixed>>> only what the "show" choice asks for
     */
    protected function shown(array $events): array
    {
        if ($this->show === 'all') {
            return $events;
        }

        return array_filter(array_map(fn ($day) => array_values(array_filter($day, fn ($e) => $this->show === 'mine' ? $e['mine'] : $e['kind'] === $this->show)), $events));
    }
}
