<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectReport;
use App\Models\ProjectRisk;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The executive view of a set of projects: each project's health (the rating and reasons status
 * reports use, worked out from its records today), delivery, budget performance for those who may
 * see money, major risks and the next milestone; and across them, what is delayed, what needs
 * intervention and what lands soon. Project-level only: no task detail. R-34 P8b adds what runs
 * between them: the projects whose work waits on each other, the major risks across them and a
 * roadmap of their milestones.
 */
final class PortfolioOverview
{
    /**
     * @param  Collection<int, Project>  $projects  already limited to those the viewer can see
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, intervene: list<array{project: Project, reasons: list<string>}>, milestones: list<array{project: Project, title: string, due: Carbon, late: bool}>, money: array{budget: int, forecast: int, projects: int, hidden: int}, links: array<string, mixed>, risks: array<string, mixed>, roadmap: array<string, mixed>}
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
                $ev = app(EarnedValue::class)->compute($p, null, $t);
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
            'links' => $this->links($projects),
            'risks' => $this->majorRisks($projects),
            'roadmap' => $this->roadmap($projects, $today),
        ];
    }

    /**
     * Pairs of projects in the set where work in one waits on work in the other: how many tasks
     * are linked, how many are still held up, and how many start before the work they wait for is
     * due. Links to projects outside the set are counted, never named.
     *
     * @param  Collection<int, Project>  $projects
     * @return array{pairs: list<array{waiting: Project, on: Project, links: int, open: int, conflicts: int}>, outside: int}
     */
    public function links(Collection $projects): array
    {
        $ids = $projects->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return ['pairs' => [], 'outside' => 0];
        }
        $edges = DB::table('task_dependencies')
            ->join('project_tasks as s', 's.id', '=', 'task_dependencies.successor_id')
            ->join('project_tasks as p', 'p.id', '=', 'task_dependencies.predecessor_id')
            ->whereColumn('s.project_id', '!=', 'p.project_id')
            ->where(fn ($q) => $q->whereIn('s.project_id', $ids)->orWhereIn('p.project_id', $ids))
            ->get(['s.project_id as sp', 'p.project_id as pp', 's.status as ss', 'p.status as ps', 's.start_date as sstart', 's.due_date as sdue', 'p.due_date as pdue']);

        $byId = $projects->keyBy('id');
        $pairs = [];
        $outside = 0;
        foreach ($edges as $e) {
            [$sp, $pp] = [(int) $e->sp, (int) $e->pp];
            if (! $byId->has($sp) || ! $byId->has($pp)) {
                $outside++;

                continue;
            }
            $key = $sp.'-'.$pp;
            $pairs[$key] ??= ['waiting' => $byId[$sp], 'on' => $byId[$pp], 'links' => 0, 'open' => 0, 'conflicts' => 0];
            $pairs[$key]['links']++;
            if ($e->ss !== 'done' && $e->ps !== 'done') {
                $pairs[$key]['open']++;
                $start = $e->sstart ?? $e->sdue;
                if ($start !== null && $e->pdue !== null && Carbon::parse($start)->lte(Carbon::parse($e->pdue))) {
                    $pairs[$key]['conflicts']++;
                }
            }
        }
        $pairs = array_values($pairs);
        usort($pairs, fn ($a, $b) => [$b['conflicts'], $b['open'], $a['waiting']->name] <=> [$a['conflicts'], $a['open'], $b['waiting']->name]);

        return ['pairs' => $pairs, 'outside' => $outside];
    }

    /**
     * Live risks rated high or critical on each project's own matrix, across the set, worst first.
     *
     * @param  Collection<int, Project>  $projects
     * @return array{items: list<array{project: Project, risk: ProjectRisk, band: string, score: int}>, total: int}
     */
    public function majorRisks(Collection $projects, int $limit = 8): array
    {
        $items = [];
        foreach ($projects as $p) {
            $matrix = RiskMatrix::forProject($p);
            foreach ($p->risks()->whereIn('status', ProjectRisk::LIVE)->with('owner:id,name')->get() as $r) {
                $band = $r->band($matrix);
                if (in_array($band, ['high', 'critical'], true)) {
                    $items[] = ['project' => $p, 'risk' => $r, 'band' => $band, 'score' => $r->currentScore()];
                }
            }
        }
        usort($items, fn ($a, $b) => [$a['band'] === 'critical' ? 0 : 1, $b['risk']->escalated_at !== null, $b['score']] <=> [$b['band'] === 'critical' ? 0 : 1, $a['risk']->escalated_at !== null, $a['score']]);

        return ['items' => array_slice($items, 0, $limit), 'total' => count($items)];
    }

    /**
     * Every project's milestones over six months from the start of this month, with open ones
     * already late pulled in at the left edge, so a roadmap can place each one on a shared axis.
     *
     * @param  Collection<int, Project>  $projects
     * @return array{from: Carbon, to: Carbon, months: list<array{label: Carbon, at: float}>, lanes: list<array{project: Project, marks: list<array{at: float, state: string, items: list<array{title: string, due: Carbon, state: string, at: float}>}>}>, undated: int, today: float}
     */
    public function roadmap(Collection $projects, Carbon $today): array
    {
        $from = $today->copy()->startOfMonth();
        $to = $from->copy()->addMonths(6)->subDay();
        $span = max(1, (int) $from->diffInDays($to));
        $lanes = [];
        $undated = 0;
        foreach ($projects as $p) {
            $marks = [];
            foreach ($p->milestones()->get(['id', 'title', 'status', 'due_date']) as $m) {
                if ($m->due_date === null) {
                    $undated += $m->status === 'completed' ? 0 : 1;

                    continue;
                }
                $done = $m->status === 'completed';
                $late = ! $done && $m->due_date->lt($today);
                if ($m->due_date->gt($to) || ($m->due_date->lt($from) && ! $late)) {
                    continue;
                }
                $at = $m->due_date->lt($from) ? 0.0 : round($from->diffInDays($m->due_date) / $span * 100, 2);
                $marks[] = ['title' => $m->title, 'due' => $m->due_date, 'state' => $done ? 'done' : ($late ? 'late' : 'due'), 'at' => $at];
            }
            if ($marks !== []) {
                usort($marks, fn ($a, $b) => $a['due']->timestamp <=> $b['due']->timestamp);
                $lanes[] = ['project' => $p, 'marks' => self::cluster($marks)];
            }
        }
        $months = [];
        for ($m = $from->copy(); $m->lte($to); $m->addMonth()) {
            $months[] = ['label' => $m->copy(), 'at' => round($from->diffInDays($m) / $span * 100, 2)];
        }

        return ['from' => $from, 'to' => $to, 'months' => $months, 'lanes' => $lanes, 'undated' => $undated, 'today' => round($from->diffInDays($today) / $span * 100, 2)];
    }

    /**
     * Milestones closer than two percent of the axis (about four days) would draw on top of each
     * other, so they share one mark: placed at the first, coloured by the worst state among them
     * (late, then due, then complete), and naming every one.
     *
     * @param  list<array{title: string, due: Carbon, state: string, at: float}>  $marks  in date order
     * @return list<array{at: float, state: string, items: list<array{title: string, due: Carbon, state: string, at: float}>}>
     */
    private static function cluster(array $marks): array
    {
        $groups = [];
        foreach ($marks as $m) {
            $last = array_key_last($groups);
            if ($last !== null && $m['at'] - $groups[$last]['at'] < 2) {
                $groups[$last]['items'][] = $m;

                continue;
            }
            $groups[] = ['at' => $m['at'], 'state' => $m['state'], 'items' => [$m]];
        }
        foreach ($groups as &$g) {
            $states = array_column($g['items'], 'state');
            $g['state'] = in_array('late', $states, true) ? 'late' : (in_array('due', $states, true) ? 'due' : 'done');
        }

        return $groups;
    }

    private static function rank(string $health): int
    {
        return ['off_track' => 0, 'at_risk' => 1, 'on_track' => 2][$health] ?? 3;
    }
}
