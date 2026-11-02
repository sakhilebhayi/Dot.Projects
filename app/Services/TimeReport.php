<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A project's logged time for a period: totals, the billable share, by task, by person and by
 * week; and, across the whole project, each task's time against its estimate. Only stopped
 * entries count (a running timer has not ended yet). With $approvedOnly, only weeks an approver
 * has approved.
 */
final class TimeReport
{
    public const PERIODS = ['this_week' => 'This week', 'last_week' => 'Last week', 'this_month' => 'This month', 'last_month' => 'Last month', 'all' => 'Since the start', 'custom' => 'Dates'];

    /** @return array{0: Carbon, 1: Carbon} */
    public static function period(string $preset, ?string $from, ?string $to, Project $project): array
    {
        $today = Carbon::today();

        return match ($preset) {
            'this_week' => [TimeTracking::weekStart($today), TimeTracking::weekStart($today)->addDays(6)],
            'last_week' => [TimeTracking::weekStart($today)->subWeek(), TimeTracking::weekStart($today)->subDay()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'all' => [Carbon::parse(TimeEntry::query()->where('project_id', $project->id)->min('work_date') ?? $today)->startOfDay(), $today],
            'custom' => self::custom($from, $to, $today),
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()->startOfDay()],
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private static function custom(?string $from, ?string $to, Carbon $today): array
    {
        try {
            $f = $from ? Carbon::parse($from)->startOfDay() : $today->copy()->startOfMonth();
            $t = $to ? Carbon::parse($to)->startOfDay() : $today;
        } catch (\Throwable) {
            return [$today->copy()->startOfMonth(), $today];
        }

        return $f->lte($t) ? [$f, $t] : [$t, $f];
    }

    /** @return Collection<int, TimeEntry> */
    public function entries(Project $project, Carbon $from, Carbon $to, bool $approvedOnly): Collection
    {
        $entries = TimeEntry::query()->where('project_id', $project->id)->whereNull('running_since')
            ->whereDate('work_date', '>=', $from->toDateString())->whereDate('work_date', '<=', $to->toDateString())
            ->with(['user:id,name', 'task:id,title'])->orderBy('work_date')->orderBy('id')->get();
        if (! $approvedOnly || $entries->isEmpty()) {
            return $entries;
        }
        $approved = Timesheet::query()->where('team_id', $project->team_id)->where('status', 'approved')->whereIn('user_id', $entries->pluck('user_id')->unique())->get()
            ->map(fn (Timesheet $s) => $s->user_id.'|'.$s->week_start->toDateString())->flip();

        return $entries->filter(fn (TimeEntry $e) => $approved->has($e->user_id.'|'.TimeTracking::weekStart($e->work_date)->toDateString()))->values();
    }

    /**
     * @return array{total: int, billable: int, count: int, people: int,
     *   byTask: list<array{task: string|null, minutes: int, billable: int}>,
     *   byPerson: list<array{name: string, minutes: int, billable: int}>,
     *   byWeek: list<array{week: Carbon, minutes: int}>}
     */
    public function summary(Collection $entries): array
    {
        $sum = fn (Collection $c) => (int) $c->sum('minutes');
        $bill = fn (Collection $c) => (int) $c->where('billable', true)->sum('minutes');

        return [
            'total' => $sum($entries),
            'billable' => $bill($entries),
            'count' => $entries->count(),
            'people' => $entries->pluck('user_id')->unique()->count(),
            'byTask' => $entries->groupBy(fn ($e) => (int) $e->task_id)->map(fn ($g) => ['task' => $g->first()->task?->title, 'minutes' => $sum($g), 'billable' => $bill($g)])->sortByDesc('minutes')->values()->all(),
            'byPerson' => $entries->groupBy('user_id')->map(fn ($g) => ['name' => (string) $g->first()->user?->name, 'minutes' => $sum($g), 'billable' => $bill($g)])->sortByDesc('minutes')->values()->all(),
            'byWeek' => $entries->groupBy(fn ($e) => TimeTracking::weekStart($e->work_date)->toDateString())->map(fn ($g, $w) => ['week' => Carbon::parse($w), 'minutes' => $sum($g)])->sortBy(fn ($r) => $r['week']->timestamp)->values()->all(),
        ];
    }

    /**
     * Every task with an estimate or logged time, over the whole project: estimate, time to date,
     * and how far over (positive) or under. Tasks most over their estimate come first.
     *
     * @return list<array{task: ProjectTask, estimate: int|null, logged: int, over: int|null}>
     */
    public function estimates(Project $project): array
    {
        $logged = TimeEntry::query()->where('project_id', $project->id)->whereNull('running_since')->whereNotNull('task_id')
            ->selectRaw('task_id, sum(minutes) as m')->groupBy('task_id')->pluck('m', 'task_id');
        $tasks = $project->tasks()->where(fn ($q) => $q->whereNotNull('estimate_minutes')->orWhereIn('id', $logged->keys()))->get(['id', 'title', 'status', 'estimate_minutes']);

        return $tasks->map(function (ProjectTask $t) use ($logged) {
            $m = (int) ($logged[$t->id] ?? 0);

            return ['task' => $t, 'estimate' => $t->estimate_minutes, 'logged' => $m, 'over' => $t->estimate_minutes ? $m - $t->estimate_minutes : null];
        })->sortByDesc(fn ($r) => $r['over'] === null ? PHP_INT_MIN : $r['over'] / max(1, (int) $r['estimate']))->values()->all();
    }
}
