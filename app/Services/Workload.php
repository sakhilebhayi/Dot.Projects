<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Team;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Planned load against capacity, per person, per week, across a business's projects.
 *
 * Rules (calendar weeks from Monday, working days Monday to Friday):
 *  - Load comes from open tasks given to the person that have an estimate: what is left of it
 *    (estimate less time logged on the task), spread evenly over the working days from today (or
 *    the task's start, if later) to its due date.
 *  - Work past its due date lands in this week, and is flagged as overdue.
 *  - Estimated work with no due date, and tasks with no estimate, cannot be placed in a week; they
 *    are counted as unknowns, never guessed.
 *  - Capacity is the person's project hours a week in this business (40 unless set).
 *  - Over capacity: planned load above capacity in a week.
 */
final class Workload
{
    public const DEFAULT_MINUTES = 40 * 60;

    public const WEEKS = 6;

    /** Who may see everyone's load and set capacity: the business owner and its admins. */
    public static function oversees(User $user, Team $team): bool
    {
        return $user->ownsTeam($team) || $user->hasTeamPermission($team, 'delete');
    }

    /** @return array<int, array{minutes: int, note: string|null}> keyed by user id */
    public function capacities(Team $team): array
    {
        return DB::table('person_capacity')->where('team_id', $team->id)->get(['user_id', 'minutes_per_week', 'note'])
            ->mapWithKeys(fn ($r) => [(int) $r->user_id => ['minutes' => (int) $r->minutes_per_week, 'note' => $r->note]])->all();
    }

    public function setCapacity(Team $team, User $by, int $userId, ?int $minutes, ?string $note): void
    {
        if (! self::oversees($by, $team)) {
            throw ValidationException::withMessages(['capacity' => 'Only the business owner or an admin sets capacity.']);
        }
        if ($team->allUsers()->firstWhere('id', $userId) === null) {
            throw ValidationException::withMessages(['capacity' => 'Choose someone in this business.']);
        }
        if ($minutes === null || $minutes < 0 || $minutes > 80 * 60) {
            throw ValidationException::withMessages(['capacity' => 'Give the project hours a week, from 0 to 80.']);
        }
        $note = trim((string) $note);
        DB::table('person_capacity')->updateOrInsert(['team_id' => $team->id, 'user_id' => $userId], ['minutes_per_week' => $minutes, 'note' => $note === '' ? null : mb_substr($note, 0, 120), 'set_by' => $by->id, 'updated_at' => Carbon::now(), 'created_at' => Carbon::now()]);
    }

    /**
     * @param  Collection<int, User>  $people
     * @return list<array{user: User, capacity: int, note: string|null, weeks: array<string, array{minutes: int, items: list<array{task: ProjectTask, project: string|null, minutes: int}>}>, overdue: int, undated: int, noEstimate: int, lastWeek: int}>
     */
    public function forPeople(Team $team, Collection $people, User $viewer, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $monday = TimeTracking::weekStart($today);
        $weeks = collect(range(0, self::WEEKS - 1))->map(fn ($i) => $monday->copy()->addWeeks($i)->toDateString())->all();
        $capacities = $this->capacities($team);
        $ids = $people->pluck('id');

        $projects = Project::withoutTeamScope(fn () => Project::query()->with('team')->where('team_id', $team->id)->whereNull('archived_at')->get())->keyBy('id');
        $tasks = ProjectTask::query()->whereIn('project_id', $projects->keys())->whereIn('assignee_id', $ids)->where('status', '!=', 'done')->get(['id', 'project_id', 'assignee_id', 'title', 'start_date', 'due_date', 'estimate_minutes']);
        $logged = TimeEntry::query()->whereIn('task_id', $tasks->pluck('id'))->whereNull('running_since')->selectRaw('task_id, sum(minutes) as m')->groupBy('task_id')->pluck('m', 'task_id');
        $lastWeek = TimeEntry::query()->where('team_id', $team->id)->whereIn('user_id', $ids)->whereNull('running_since')
            ->whereDate('work_date', '>=', $monday->copy()->subWeek()->toDateString())->whereDate('work_date', '<=', $monday->copy()->subDay()->toDateString())
            ->selectRaw('user_id, sum(minutes) as m')->groupBy('user_id')->pluck('m', 'user_id');
        $visible = $projects->map(fn (Project $p) => $p->canBeSeenBy($viewer));
        // Looked up by id and by person once: scanning the whole task list inside the loops below made a
        // business with a few thousand open tasks take seconds (R-34 P12c load test).
        $byId = $tasks->keyBy('id');
        $byPerson = $tasks->groupBy('assignee_id');

        $out = [];
        foreach ($people as $person) {
            $row = ['user' => $person, 'capacity' => $capacities[$person->id]['minutes'] ?? self::DEFAULT_MINUTES, 'note' => $capacities[$person->id]['note'] ?? null,
                'weeks' => array_fill_keys($weeks, ['minutes' => 0, 'items' => []]), 'overdue' => 0, 'undated' => 0, 'noEstimate' => 0, 'lastWeek' => (int) ($lastWeek[$person->id] ?? 0)];
            $byWeek = array_fill_keys($weeks, []);
            foreach ($byPerson->get($person->id, collect()) as $t) {
                if (! $t->estimate_minutes) {
                    $row['noEstimate']++;

                    continue;
                }
                $left = max(0, $t->estimate_minutes - (int) ($logged[$t->id] ?? 0));
                if ($left === 0) {
                    continue;
                }
                if ($t->due_date === null) {
                    $row['undated'] += $left;

                    continue;
                }
                foreach ($this->spread($left, $t->start_date, $t->due_date, $today) as $week => $minutes) {
                    if ($t->due_date->lt($today)) {
                        $row['overdue'] += $minutes;
                    }
                    if (isset($byWeek[$week])) {
                        $byWeek[$week][$t->id] = ($byWeek[$week][$t->id] ?? 0) + $minutes;
                    }
                }
            }
            foreach ($byWeek as $week => $perTask) {
                $items = [];
                foreach ($perTask as $taskId => $minutes) {
                    $t = $byId[$taskId];
                    $items[] = ['task' => $t, 'project' => ($visible[$t->project_id] ?? false) ? $projects[$t->project_id]->name : null, 'minutes' => (int) round($minutes)];
                }
                usort($items, fn ($a, $b) => $b['minutes'] <=> $a['minutes']);
                $row['weeks'][$week] = ['minutes' => (int) round(array_sum($perTask)), 'items' => $items];
            }
            $row['overdue'] = (int) round($row['overdue']);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Spreads minutes over the working days from max(today, start) to due, keyed by week start.
     * Past-due work goes into this week; a window with no working day uses the due date's week.
     *
     * @return array<string, float>
     */
    private function spread(int $minutes, ?Carbon $start, Carbon $due, Carbon $today): array
    {
        $thisWeek = TimeTracking::weekStart($today)->toDateString();
        if ($due->lt($today)) {
            return [$thisWeek => (float) $minutes];
        }
        $from = $start !== null && $start->gt($today) ? $start->copy()->startOfDay() : $today->copy();
        if ($from->gt($due)) {
            $from = $due->copy();
        }
        $days = [];
        for ($d = $from->copy(); $d->lte($due); $d->addDay()) {
            if (! $d->isWeekend()) {
                $days[] = TimeTracking::weekStart($d)->toDateString();
            }
        }
        if ($days === []) {
            return [TimeTracking::weekStart($due)->toDateString() => (float) $minutes];
        }
        $per = $minutes / count($days);
        $out = [];
        foreach ($days as $week) {
            $out[$week] = ($out[$week] ?? 0) + $per;
        }

        return $out;
    }
}
