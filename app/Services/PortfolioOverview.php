<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectReport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The executive view of a set of projects: each project's health (the rating and reasons status
 * reports use, worked out from its records today), delivery, budget performance for those who may
 * see money, major risks and the next milestone; and across them, what is delayed, what needs
 * intervention and what lands soon. Project-level only: no task detail.
 */
final class PortfolioOverview
{
    /**
     * @param  Collection<int, Project>  $projects  already limited to those the viewer can see
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, intervene: list<array{project: Project, reasons: list<string>}>, milestones: list<array{project: Project, title: string, due: Carbon, late: bool}>, money: array{budget: int, forecast: int, projects: int, hidden: int}}
     */
    public function build(Collection $projects, User $viewer): array
    {
        $today = Carbon::today();
        $reports = app(StatusReports::class);
        $rows = [];
        $milestones = [];
        $money = ['budget' => 0, 'forecast' => 0, 'projects' => 0, 'hidden' => 0];

        foreach ($projects as $p) {
            $f = $reports->facts($p, $today->copy()->subDays(6), $today);
            $health = $reports->suggest($f);
            $latest = $p->reports()->where('status', 'published')->orderByDesc('number')->first(['id', 'number', 'overall', 'period_to']);
            $w = $f['work'];
            $row = [
                'project' => $p,
                'health' => $health['rating'],
                'reasons' => $health['reasons'],
                'reported' => $latest instanceof ProjectReport ? ['rating' => $latest->overall, 'on' => $latest->period_to] : null,
                'done' => $w['total'] > 0 ? (int) round($w['done'] / $w['total'] * 100) : null,
                'due' => $f['schedule']['due'],
                'forecast' => $f['schedule']['forecast'],
                'late' => $f['schedule']['days_late'],
                'risks' => $f['risks']['serious'],
                'escalated' => $f['risks']['escalated'],
                'critical' => $f['issues']['critical'],
                'milestone' => $f['milestone'],
                'money' => null,
            ];
            if ($p->moneyVisibleTo($viewer) && $p->budgetLines()->exists()) {
                $t = app(CostLedger::class)->ledger($p)['total'];
                $ev = app(EarnedValue::class)->compute($p);
                $row['money'] = ['budget' => $t['budget'], 'forecast' => $t['forecast'], 'variance' => $t['variance'], 'cpi' => $ev['cpi'], 'spi' => $ev['spi']];
                $money['budget'] += $t['budget'];
                $money['forecast'] += $t['forecast'];
                $money['projects']++;
            } elseif (! $p->moneyVisibleTo($viewer)) {
                $money['hidden']++;
            }
            $rows[] = $row;

            if ($f['milestone'] && $f['milestone']['due']) {
                $due = Carbon::parse($f['milestone']['due']);
                if ($due->lte($today->copy()->addDays(30))) {
                    $milestones[] = ['project' => $p, 'title' => $f['milestone']['title'], 'due' => $due, 'late' => (bool) $f['milestone']['late']];
                }
            }
        }

        $intervene = collect($rows)->map(function ($r) {
            $why = array_values(array_filter([
                $r['health'] === 'off_track' ? 'Off track: '.mb_strtolower($r['reasons'][0] ?? '') : null,
                $r['escalated'] ? $r['escalated'].' '.str('risk')->plural($r['escalated']).' escalated' : null,
                $r['critical'] ? $r['critical'].' critical '.str('issue')->plural($r['critical']).' open' : null,
            ]));

            return $why ? ['project' => $r['project'], 'reasons' => $why] : null;
        })->filter()->values()->all();

        usort($rows, fn ($a, $b) => [self::rank($a['health']), $b['late']] <=> [self::rank($b['health']), $a['late']]);
        usort($milestones, fn ($a, $b) => $a['due']->timestamp <=> $b['due']->timestamp);

        return [
            'rows' => $rows,
            'counts' => ['on_track' => count(array_filter($rows, fn ($r) => $r['health'] === 'on_track')), 'at_risk' => count(array_filter($rows, fn ($r) => $r['health'] === 'at_risk')), 'off_track' => count(array_filter($rows, fn ($r) => $r['health'] === 'off_track')), 'delayed' => count(array_filter($rows, fn ($r) => $r['late'] > 0 || ($r['milestone']['late'] ?? false)))],
            'intervene' => $intervene,
            'milestones' => $milestones,
            'money' => $money,
        ];
    }

    private static function rank(string $health): int
    {
        return ['off_track' => 0, 'at_risk' => 1, 'on_track' => 2][$health] ?? 3;
    }
}
