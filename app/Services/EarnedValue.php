<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Support\Carbon;

/**
 * Earned value for a project on a date. Every figure is in cents and comes from the project's
 * own records; the method is fixed and stated:
 *
 *   BAC  budget at completion   = total budget (the cost ledger)
 *   AC   actual cost            = actual costs to date, with approved time at rates (the ledger)
 *   Work is measured over leaf tasks (tasks with no subtasks). A task's weight is its estimate;
 *   a task with no estimate weighs the average estimate of those that have one (all weigh the
 *   same when none has one).
 *   PV   planned value  = BAC x share of the weighted work planned to be done by the date. Planned
 *        dates come from the latest baseline (or the current dates when there is none); a task is
 *        planned evenly from its start to its due date, inclusive; undated work is never planned.
 *   EV   earned value   = BAC x share of the weighted work done, by the 0/50/100 rule: a task not
 *        started counts 0%, a started one (in progress or in review) 50%, a done one 100%.
 *   SV = EV - PV        SPI = EV / PV       (behind schedule when below 1)
 *   CV = EV - AC        CPI = EV / AC       (over cost when below 1)
 *   EAC = BAC / CPI     estimate at completion, if cost performance continues as it has
 *   ETC = EAC - AC      VAC = BAC - EAC     TCPI = (BAC - EV) / (BAC - AC)
 *   An index with nothing to divide by is not shown rather than invented. Derived amounts are
 *   rounded to whole rand: cents would claim a precision the method does not have.
 */
final class EarnedValue
{
    public const STARTED = ['in_progress', 'review'];

    /**
     * @param  array<string, int>|null  $ledgerTotal  CostLedger::ledger()['total'] when the caller already has it
     * @return array{asOf: Carbon, bac: int, pv: int, ev: int, ac: int, sv: int, cv: int, spi: float|null, cpi: float|null,
     *   eac: int|null, etc: int|null, vac: int|null, tcpi: float|null, baseline: int|null, tasks: int, dated: int, coverage: int, weighted: bool}
     */
    public function compute(Project $project, ?Carbon $asOf = null, ?array $ledgerTotal = null): array
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        // Callers that have just worked out the ledger pass its total, so a page of projects loads each budget once.
        $ledger = $ledgerTotal ?? app(CostLedger::class)->ledger($project)['total'];
        $bac = $ledger['budget'];
        $ac = $ledger['actual'];

        $all = $project->tasks()->get(['id', 'parent_id', 'status', 'start_date', 'due_date', 'estimate_minutes']);
        $parents = $all->pluck('parent_id')->filter()->unique()->flip();
        $leaves = $all->reject(fn (ProjectTask $t) => $parents->has($t->id))->values();
        $estimated = $leaves->pluck('estimate_minutes')->filter();
        $fallback = $estimated->isEmpty() ? 1.0 : (float) $estimated->avg();
        $weight = fn (ProjectTask $t) => $t->estimate_minutes ? (float) $t->estimate_minutes : $fallback;

        $baseline = $project->baselines()->first();
        $planned = $baseline ? $baseline->items()->where('item_type', 'task')->get()->keyBy('item_id') : collect();

        $total = 0.0;
        $plannedShare = 0.0;
        $earnedShare = 0.0;
        $datedWeight = 0.0;
        foreach ($leaves as $t) {
            $w = $weight($t);
            $total += $w;
            $item = $planned->get($t->id);
            $start = $item ? ($item->start_date ?? $item->due_date) : ($t->start_date ?? $t->due_date);
            $due = $item ? $item->due_date : $t->due_date;
            if ($due !== null) {
                $datedWeight += $w;
                $plannedShare += $w * $this->plannedFraction(Carbon::parse($start), Carbon::parse($due), $asOf);
            }
            $earnedShare += $w * match (true) {
                $t->status === 'done' => 1.0,
                in_array($t->status, self::STARTED, true) => 0.5,
                default => 0.0,
            };
        }

        $rand = fn (float $cents) => (int) round($cents / 100) * 100;
        $pv = $total > 0 ? $rand($bac * $plannedShare / $total) : 0;
        $ev = $total > 0 ? $rand($bac * $earnedShare / $total) : 0;
        $spi = $pv > 0 ? round($ev / $pv, 2) : null;
        $cpi = $ac > 0 && $ev > 0 ? round($ev / $ac, 2) : null;
        $eac = $cpi !== null ? $rand($bac / ($ev / $ac)) : null;

        return [
            'asOf' => $asOf, 'bac' => $bac, 'pv' => $pv, 'ev' => $ev, 'ac' => $ac, 'sv' => $ev - $pv, 'cv' => $ev - $ac,
            'spi' => $spi, 'cpi' => $cpi, 'eac' => $eac, 'etc' => $eac !== null ? max(0, $eac - $ac) : null, 'vac' => $eac !== null ? $bac - $eac : null,
            'tcpi' => $bac > $ac ? round(($bac - $ev) / ($bac - $ac), 2) : null,
            'baseline' => $baseline?->number, 'tasks' => $leaves->count(), 'dated' => $leaves->filter(fn ($t) => $t->due_date !== null || $planned->has($t->id))->count(),
            'coverage' => $total > 0 ? (int) round($datedWeight / $total * 100) : 0, 'weighted' => $estimated->isNotEmpty(),
        ];
    }

    private function plannedFraction(Carbon $start, Carbon $due, Carbon $asOf): float
    {
        if ($start->gt($due)) {
            $start = $due->copy();
        }
        if ($asOf->lt($start)) {
            return 0.0;
        }
        if ($asOf->gte($due)) {
            return 1.0;
        }

        return ((int) $start->diffInDays($asOf) + 1) / ((int) $start->diffInDays($due) + 1);
    }
}
