<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Team;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Labour cost: time in approved weeks, at each person's hourly rate in force on the day worked.
 * Rates are dated, per person per business, and seen and set only by the business owner and
 * admins. Hours from someone without a rate are counted as unpriced, never priced by guesswork.
 */
final class LabourCost
{
    /** @return array<int, list<array{from: string, cents: int}>> user id => rates, newest first */
    public function rates(Team $team): array
    {
        return DB::table('person_rates')->where('team_id', $team->id)->orderByDesc('effective_from')->get()
            ->groupBy('user_id')->map(fn (Collection $g) => $g->map(fn ($r) => ['from' => Carbon::parse($r->effective_from)->toDateString(), 'cents' => (int) $r->hourly_cents])->values()->all())->all();
    }

    public function setRate(Team $team, User $by, int $userId, ?int $cents, ?string $from): void
    {
        if (! Workload::oversees($by, $team)) {
            throw ValidationException::withMessages(['rate' => 'Only the business owner or an admin sets rates.']);
        }
        if ($team->allUsers()->firstWhere('id', $userId) === null) {
            throw ValidationException::withMessages(['rate' => 'Choose someone in this business.']);
        }
        if ($cents === null || $cents < 0 || $cents > 10_000_000) {
            throw ValidationException::withMessages(['rate' => 'Give the hourly rate in rand, up to R 100 000.']);
        }
        try {
            $date = Carbon::parse((string) $from)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['rate' => 'Give the date the rate applies from.']);
        }
        DB::table('person_rates')->updateOrInsert(['team_id' => $team->id, 'user_id' => $userId, 'effective_from' => $date], ['hourly_cents' => $cents, 'set_by' => $by->id, 'updated_at' => Carbon::now(), 'created_at' => Carbon::now()]);
    }

    /** @param array<int, list<array{from: string, cents: int}>> $rates */
    public static function rateOn(array $rates, int $userId, Carbon $day): ?int
    {
        foreach ($rates[$userId] ?? [] as $r) {
            if ($r['from'] <= $day->toDateString()) {
                return $r['cents'];
            }
        }

        return null;
    }

    /**
     * The project's labour cost to date from approved time.
     *
     * @return array{cents: int, minutes: int, unpriced: int, people: list<array{name: string, minutes: int, cents: int, unpriced: int}>}
     */
    public function forProject(Project $project): array
    {
        $rates = $this->rates($project->team);
        $entries = app(TimeReport::class)->entries($project, Carbon::parse('2000-01-01'), Carbon::today(), true);
        $people = [];
        $cents = 0;
        $unpriced = 0;
        foreach ($entries as $e) {
            /** @var TimeEntry $e */
            $rate = self::rateOn($rates, $e->user_id, $e->work_date);
            $p = &$people[$e->user_id];
            $p ??= ['name' => (string) $e->user->name, 'minutes' => 0, 'cents' => 0, 'unpriced' => 0];
            $p['minutes'] += (int) $e->minutes;
            if ($rate === null) {
                $p['unpriced'] += (int) $e->minutes;
                $unpriced += (int) $e->minutes;
            } else {
                $c = (int) round($e->minutes * $rate / 60);
                $p['cents'] += $c;
                $cents += $c;
            }
            unset($p);
        }
        usort($people, fn ($a, $b) => $b['minutes'] <=> $a['minutes']);

        return ['cents' => $cents, 'minutes' => (int) $entries->sum('minutes'), 'unpriced' => $unpriced, 'people' => $people];
    }
}
