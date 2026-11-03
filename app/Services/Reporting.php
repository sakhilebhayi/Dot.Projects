<?php

namespace App\Services;

use App\Models\Milestone;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\ProjectIssue;
use App\Models\ProjectRisk;
use App\Models\ProjectTask;
use App\Models\Team;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The reports: each a type with stated columns, worked out for one reader from the records they
 * may see. Projects come through the team and privacy scopes; money only where the reader may see
 * it; time by person only on projects they run; utilisation of other people only for the business
 * owner and admins. What is left out for those reasons is said in the report's notes, so a total
 * is never mistaken for the whole business. Every figure comes from a service the project pages
 * already use (StatusReports facts, Schedule, CostLedger, EarnedValue, TimeReport, Workload), so a
 * report and the page it summarises always agree.
 */
final class Reporting
{
    /** Column types: how a value is shown and how it is exported. */
    public const TYPES = ['text', 'int', 'money', 'date', 'percent', 'ratio', 'hours', 'days', 'bool'];

    public const SCOPES = ['open' => 'Open projects', 'all' => 'Open and completed', 'archived' => 'Archived'];

    public const PROJECT_STATUSES = ['planning' => 'Planning', 'active' => 'Active', 'on_hold' => 'On hold', 'pending_closure_approval' => 'Closing', 'completed' => 'Completed'];

    /**
     * Every report: what one row is, whether it covers a period, and its columns as key =>
     * [label, type, shown by default].
     *
     * @var array<string, array{label: string, about: string, row: string, period: bool, columns: array<string, array{0: string, 1: string, 2: bool}>}>
     */
    public const REPORTS = [
        'executive' => [
            'label' => 'Projects compared',
            'about' => 'Health, delivery, risk and money side by side, for an executive view or a portfolio review.',
            'row' => 'project', 'period' => false,
            'columns' => [
                'project' => ['Project', 'text', true], 'health' => ['Health', 'text', true], 'done_pct' => ['Done', 'percent', true],
                'days_late' => ['Days late', 'days', true], 'slip' => ['Slip on baseline', 'days', false], 'serious_risks' => ['Serious risks', 'int', true],
                'critical_issues' => ['Critical issues', 'int', true], 'open_issues' => ['Open issues', 'int', false], 'budget' => ['Budget', 'money', true],
                'forecast_cost' => ['Forecast cost', 'money', true], 'variance' => ['Variance', 'money', true], 'cpi' => ['CPI', 'ratio', true], 'spi' => ['SPI', 'ratio', true],
            ],
        ],
        'progress' => [
            'label' => 'Progress and health',
            'about' => 'Where each project stands: its health by the status-report rule, its work, and its next milestone.',
            'row' => 'project', 'period' => false,
            'columns' => [
                'project' => ['Project', 'text', true], 'status' => ['Status', 'text', false], 'owner' => ['Owner', 'text', true], 'health' => ['Health', 'text', true],
                'reason' => ['Because', 'text', true], 'done_pct' => ['Done', 'percent', true], 'tasks' => ['Tasks', 'int', false], 'done' => ['Done tasks', 'int', false],
                'overdue' => ['Overdue', 'int', true], 'blocked' => ['Blocked', 'int', true], 'next_milestone' => ['Next milestone', 'text', true],
                'milestone_due' => ['Milestone due', 'date', true], 'due' => ['Due', 'date', false], 'forecast' => ['Finishes', 'date', true], 'days_late' => ['Days late', 'days', true],
            ],
        ],
        'schedule' => [
            'label' => 'Schedule performance',
            'about' => 'Finish against the due date and the latest baseline, the critical path, broken dependencies and SPI.',
            'row' => 'project', 'period' => false,
            'columns' => [
                'project' => ['Project', 'text', true], 'due' => ['Due', 'date', true], 'baseline' => ['Baseline', 'int', false], 'baseline_due' => ['Baseline due', 'date', true],
                'forecast' => ['Finishes', 'date', true], 'days_late' => ['Days late', 'days', true], 'slip' => ['Slip on baseline', 'days', true],
                'critical' => ['Critical tasks', 'int', true], 'broken' => ['Broken dependencies', 'int', true], 'spi' => ['SPI', 'ratio', true],
            ],
        ],
        'budget' => [
            'label' => 'Budget and earned value',
            'about' => 'The cost ledger and earned value, by the formulas on each project\'s Money tab. Only projects whose money you may see.',
            'row' => 'project', 'period' => false,
            'columns' => [
                'project' => ['Project', 'text', true], 'budget' => ['Budget', 'money', true], 'actual' => ['Actual', 'money', true], 'committed' => ['Committed', 'money', false],
                'forecast_cost' => ['Forecast', 'money', true], 'variance' => ['Variance', 'money', true], 'used' => ['Used', 'percent', false],
                'pv' => ['PV', 'money', true], 'ev' => ['EV', 'money', true], 'ac' => ['AC', 'money', false], 'sv' => ['SV', 'money', true], 'cv' => ['CV', 'money', true],
                'spi' => ['SPI', 'ratio', true], 'cpi' => ['CPI', 'ratio', true], 'eac' => ['EAC', 'money', true], 'etc' => ['ETC', 'money', false],
                'vac' => ['VAC', 'money', false], 'tcpi' => ['TCPI', 'ratio', false], 'coverage' => ['Dated work', 'percent', false],
            ],
        ],
        'risks' => [
            'label' => 'Risks',
            'about' => 'Every live risk across the projects, rated on its own project\'s matrix, worst first.',
            'row' => 'risk', 'period' => false,
            'columns' => [
                'project' => ['Project', 'text', true], 'ref' => ['Ref', 'text', true], 'title' => ['Risk', 'text', true], 'category' => ['Category', 'text', false],
                'status' => ['Status', 'text', true], 'owner' => ['Owner', 'text', true], 'probability' => ['Probability', 'int', false], 'impact' => ['Impact', 'int', false],
                'inherent' => ['Inherent score', 'int', false], 'score' => ['Score now', 'int', true], 'band' => ['Rating', 'text', true], 'escalated' => ['Escalated', 'bool', true],
                'review' => ['Review by', 'date', true], 'review_overdue' => ['Review overdue', 'bool', false], 'raised' => ['Raised', 'date', false],
            ],
        ],
        'issues' => [
            'label' => 'Issues',
            'about' => 'Every open issue across the projects, most severe first, with how long each has been open.',
            'row' => 'issue', 'period' => false,
            'columns' => [
                'project' => ['Project', 'text', true], 'ref' => ['Ref', 'text', true], 'title' => ['Issue', 'text', true], 'category' => ['Category', 'text', false],
                'severity' => ['Severity', 'text', true], 'priority' => ['Priority', 'text', false], 'status' => ['Status', 'text', true], 'owner' => ['Owner', 'text', true],
                'raised' => ['Raised', 'date', false], 'target' => ['Target', 'date', true], 'late' => ['Late', 'bool', true], 'days_open' => ['Days open', 'days', true],
                'escalated' => ['Escalated', 'bool', false],
            ],
        ],
        'tasks' => [
            'label' => 'Task completion',
            'about' => 'Work done and added in the period, what is overdue or blocked now, and how much finished on time.',
            'row' => 'project', 'period' => true,
            'columns' => [
                'project' => ['Project', 'text', true], 'tasks' => ['Tasks', 'int', true], 'open' => ['Open', 'int', false], 'done' => ['Done', 'int', false],
                'done_in_period' => ['Done in period', 'int', true], 'added_in_period' => ['Added in period', 'int', true], 'overdue' => ['Overdue', 'int', true],
                'blocked' => ['Blocked', 'int', true], 'no_estimate' => ['No estimate', 'int', false], 'done_pct' => ['Done', 'percent', true], 'on_time_pct' => ['On time', 'percent', true],
            ],
        ],
        'milestones' => [
            'label' => 'Milestone performance',
            'about' => 'Each milestone against its date and the latest baseline: when it lands as planned now, or when it finished.',
            'row' => 'milestone', 'period' => false,
            'columns' => [
                'project' => ['Project', 'text', true], 'milestone' => ['Milestone', 'text', true], 'status' => ['Status', 'text', true], 'baseline_due' => ['Baseline due', 'date', true],
                'due' => ['Due', 'date', true], 'forecast' => ['Lands', 'date', true], 'finished' => ['Finished', 'date', true], 'slip' => ['Slip', 'days', true],
                'on_time' => ['On time', 'bool', true], 'done' => ['Tasks done', 'int', false], 'tasks' => ['Tasks', 'int', false],
            ],
        ],
        'time' => [
            'label' => 'Time',
            'about' => 'Hours logged in the period by project, and by person on the projects you run.',
            'row' => 'project and person', 'period' => true,
            'columns' => [
                'project' => ['Project', 'text', true], 'person' => ['Person', 'text', true], 'hours' => ['Hours', 'hours', true],
                'billable' => ['Billable', 'hours', true], 'approved' => ['Approved', 'hours', true], 'entries' => ['Entries', 'int', false],
            ],
        ],
        'utilisation' => [
            'label' => 'Utilisation',
            'about' => 'Capacity against planned work over the next six weeks and time logged last week, by person.',
            'row' => 'person', 'period' => false,
            'columns' => [
                'person' => ['Person', 'text', true], 'capacity' => ['Capacity a week', 'hours', true], 'this_week' => ['Planned this week', 'hours', true],
                'peak' => ['Busiest week', 'hours', true], 'weeks_over' => ['Weeks over capacity', 'int', true], 'last_week' => ['Logged last week', 'hours', true],
                'utilisation' => ['Utilisation last week', 'percent', true], 'overdue' => ['Overdue work', 'hours', false], 'undated' => ['Undated work', 'hours', false],
                'no_estimate' => ['Tasks without estimate', 'int', false],
            ],
        ],
    ];

    /** @return array{scope: string, portfolio: string, projects: list<int>, period: string, from: string, to: string} */
    public static function defaults(): array
    {
        return ['scope' => 'open', 'portfolio' => '', 'projects' => [], 'period' => 'this_month', 'from' => '', 'to' => ''];
    }

    /** @return list<string> the columns shown by default */
    public static function defaultColumns(string $type): array
    {
        return array_keys(array_filter(self::REPORTS[$type]['columns'], fn ($c) => $c[2]));
    }

    /**
     * Cleans a report's settings: an unknown type is refused, unknown columns and filters dropped,
     * and the first column always kept so every row says what it is.
     *
     * @param  array<string, mixed>  $config
     * @return array{filters: array{scope: string, portfolio: string, projects: list<int>, period: string, from: string, to: string}, columns: list<string>, sort: string|null, desc: bool}
     */
    public static function normalise(string $type, array $config): array
    {
        if (! isset(self::REPORTS[$type])) {
            throw ValidationException::withMessages(['type' => 'Choose a report.']);
        }
        $all = self::REPORTS[$type]['columns'];
        $f = array_merge(self::defaults(), array_intersect_key((array) ($config['filters'] ?? []), self::defaults()));
        $first = array_key_first($all);
        $cols = array_values(array_unique(array_filter(array_map('strval', (array) ($config['columns'] ?? self::defaultColumns($type))), fn ($c) => isset($all[$c]))));
        if (! in_array($first, $cols, true)) {
            array_unshift($cols, $first);
        }
        $sort = isset($config['sort']) && isset($all[$config['sort']]) ? (string) $config['sort'] : null;

        return [
            'filters' => [
                'scope' => isset(self::SCOPES[$f['scope']]) ? (string) $f['scope'] : 'open',
                'portfolio' => ctype_digit((string) $f['portfolio']) ? (string) $f['portfolio'] : '',
                'projects' => array_values(array_unique(array_map('intval', array_filter((array) $f['projects'], fn ($id) => ctype_digit((string) $id))))),
                'period' => isset(TimeReport::PERIODS[$f['period']]) && $f['period'] !== 'all' ? (string) $f['period'] : 'this_month',
                'from' => (string) $f['from'],
                'to' => (string) $f['to'],
            ],
            'columns' => $cols,
            'sort' => $sort,
            'desc' => (bool) ($config['desc'] ?? false),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} the period a report covers, from its filters */
    public static function period(array $filters): array
    {
        $today = Carbon::today();
        $monday = TimeTracking::weekStart($today);

        return match ($filters['period']) {
            'this_week' => [$monday, $monday->copy()->addDays(6)],
            'last_week' => [$monday->copy()->subWeek(), $monday->copy()->subDay()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'custom' => self::customPeriod($filters['from'], $filters['to'], $today),
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()->startOfDay()],
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private static function customPeriod(string $from, string $to, Carbon $today): array
    {
        try {
            $f = $from !== '' ? Carbon::parse($from)->startOfDay() : $today->copy()->startOfMonth();
            $t = $to !== '' ? Carbon::parse($to)->startOfDay() : $today->copy();
        } catch (\Throwable) {
            return [$today->copy()->startOfMonth(), $today->copy()];
        }

        return $f->lte($t) ? [$f, $t] : [$t, $f];
    }

    /**
     * The projects a report covers for this reader: the business's projects they can see (the
     * scopes already apply), narrowed by scope, portfolio and a chosen list.
     *
     * @param  array{scope: string, portfolio: string, projects: list<int>}  $filters
     * @return Collection<int, Project>
     */
    public function projects(Team $team, array $filters): Collection
    {
        $q = Project::query()->where('team_id', $team->id)->with('owner:id,name')->orderBy('name');
        match ($filters['scope']) {
            'archived' => $q->whereNotNull('archived_at'),
            'all' => $q->whereNull('archived_at'),
            default => $q->whereNull('archived_at')->where('status', '!=', 'completed'),
        };
        if ($filters['portfolio'] !== '') {
            $portfolio = Portfolio::query()->where('team_id', $team->id)->find((int) $filters['portfolio']);
            $q->whereIn('id', $portfolio === null ? [0] : DB::table('portfolio_project')->where('portfolio_id', $portfolio->id)->pluck('project_id'));
        }
        if ($filters['projects'] !== []) {
            $q->whereIn('id', $filters['projects']);
        }

        return $q->get();
    }

    /**
     * Runs a report for one reader.
     *
     * @param  array{filters: array<string, mixed>, columns: list<string>, sort: string|null, desc: bool}  $config  from normalise()
     * @return array{rows: list<array<string, mixed>>, notes: list<string>, totals: array<string, mixed>|null, links: list<string|null>, from: Carbon|null, to: Carbon|null, projects: int}
     */
    public function run(string $type, Team $team, User $viewer, array $config): array
    {
        $projects = $this->projects($team, $config['filters']);
        [$from, $to] = self::REPORTS[$type]['period'] ? self::period($config['filters']) : [null, null];
        $out = match ($type) {
            'executive' => $this->executive($projects, $viewer),
            'progress' => $this->progress($projects),
            'schedule' => $this->schedule($projects, $viewer),
            'budget' => $this->budget($projects, $viewer),
            'risks' => $this->risks($projects),
            'issues' => $this->issues($projects),
            'tasks' => $this->tasks($projects, $from, $to),
            'milestones' => $this->milestones($projects),
            'time' => $this->time($projects, $viewer, $from, $to),
            'utilisation' => $this->utilisation($team, $viewer),
            default => ['rows' => [], 'notes' => [], 'links' => []],
        };
        $rows = $out['rows'];
        $links = $out['links'];
        if ($config['sort'] !== null) {
            $order = array_keys($rows);
            $key = $config['sort'];
            usort($order, function ($a, $b) use ($rows, $key, $config) {
                [$x, $y] = [$rows[$a][$key] ?? null, $rows[$b][$key] ?? null];
                if ($x === null || $y === null) {
                    return ($x === null) <=> ($y === null); // empty values last, either way
                }
                $c = $x instanceof Carbon ? $x->timestamp <=> $y->timestamp : (is_string($x) ? strnatcasecmp($x, (string) $y) : $x <=> $y);

                return $config['desc'] ? -$c : $c;
            });
            $rows = array_map(fn ($i) => $rows[$i], $order);
            $links = array_map(fn ($i) => $links[$i] ?? null, $order);
        }

        return [
            'rows' => $rows,
            'notes' => $out['notes'],
            'totals' => $this->totals($type, $rows, $config['columns']),
            'links' => $links,
            'from' => $from,
            'to' => $to,
            'projects' => $projects->count(),
        ];
    }

    /**
     * A totals row for money, hours and counts, where adding them up means something: never for
     * ratios, percentages or dates, and only when every row is one project or one person.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     * @return array<string, mixed>|null
     */
    private function totals(string $type, array $rows, array $columns): ?array
    {
        if (! in_array($type, ['budget', 'tasks', 'time'], true) || count($rows) < 2) {
            return null;
        }
        $defs = self::REPORTS[$type]['columns'];
        $t = [];
        foreach ($columns as $c) {
            if (in_array($defs[$c][1], ['money', 'hours', 'int'], true)) {
                $t[$c] = array_sum(array_map(fn ($r) => (int) ($r[$c] ?? 0), $rows));
            }
        }

        return $t === [] ? null : $t;
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array{rows: list<array<string, mixed>>, notes: list<string>, links: list<string|null>}
     */
    private function executive(Collection $projects, User $viewer): array
    {
        $reports = app(StatusReports::class);
        $rows = [];
        $links = [];
        $hidden = 0;
        $today = Carbon::today();
        $facts = app(ProjectFacts::class);
        $facts->prime($projects);
        foreach ($projects as $p) {
            $f = $facts->facts($p, $today->copy()->subDays(6), $today);
            $w = $f['work'];
            $row = [
                'project' => $p->name,
                'health' => self::health($reports->suggest($f)['rating']),
                'done_pct' => $w['total'] > 0 ? (int) round($w['done'] / $w['total'] * 100) : null,
                'days_late' => $f['schedule']['days_late'],
                'slip' => self::slip($f['schedule']['baseline_due'], $f['schedule']['forecast']),
                'serious_risks' => $f['risks']['serious'],
                'critical_issues' => $f['issues']['critical'],
                'open_issues' => $f['issues']['open'],
                'budget' => null, 'forecast_cost' => null, 'variance' => null, 'cpi' => null, 'spi' => null,
            ];
            if ($p->moneyVisibleTo($viewer)) {
                if ($m = $facts->money($p)) {
                    [$t, $ev] = [$m['total'], $m['ev']];
                    $row = array_merge($row, ['budget' => $t['budget'], 'forecast_cost' => $t['forecast'], 'variance' => $t['variance'], 'cpi' => $ev['cpi'], 'spi' => $ev['spi']]);
                }
            } else {
                $hidden++;
            }
            $rows[] = $row;
            $links[] = route('projects.show', $p);
        }

        return ['rows' => $rows, 'notes' => $hidden ? [self::count($hidden, 'project').' whose money you do not see show no budget, CPI or SPI.'] : [], 'links' => $links];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array{rows: list<array<string, mixed>>, notes: list<string>, links: list<string|null>}
     */
    private function progress(Collection $projects): array
    {
        $reports = app(StatusReports::class);
        $rows = [];
        $links = [];
        $today = Carbon::today();
        $facts = app(ProjectFacts::class);
        $facts->prime($projects);
        foreach ($projects as $p) {
            $f = $facts->facts($p, $today->copy()->subDays(6), $today);
            $h = $reports->suggest($f);
            $w = $f['work'];
            $rows[] = [
                'project' => $p->name,
                'status' => self::PROJECT_STATUSES[$p->status] ?? $p->status,
                'owner' => $p->owner->name,
                'health' => self::health($h['rating']),
                'reason' => $h['reasons'][0] ?? null,
                'done_pct' => $w['total'] > 0 ? (int) round($w['done'] / $w['total'] * 100) : null,
                'tasks' => $w['total'],
                'done' => $w['done'],
                'overdue' => $w['overdue'],
                'blocked' => $w['blocked'],
                'next_milestone' => $f['milestone']['title'] ?? null,
                'milestone_due' => isset($f['milestone']['due']) ? Carbon::parse($f['milestone']['due']) : null,
                'due' => $p->due_date?->copy(),
                'forecast' => $f['schedule']['forecast'] ? Carbon::parse($f['schedule']['forecast']) : null,
                'days_late' => $f['schedule']['days_late'],
            ];
            $links[] = route('projects.show', $p);
        }

        return ['rows' => $rows, 'notes' => [], 'links' => $links];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array{rows: list<array<string, mixed>>, notes: list<string>, links: list<string|null>}
     */
    private function schedule(Collection $projects, User $viewer): array
    {
        $reports = app(StatusReports::class);
        $rows = [];
        $links = [];
        $hidden = 0;
        $today = Carbon::today();
        $facts = app(ProjectFacts::class);
        $facts->prime($projects);
        foreach ($projects as $p) {
            $s = $facts->facts($p, $today, $today)['schedule'];
            $money = $p->moneyVisibleTo($viewer);
            $hidden += $money ? 0 : 1;
            $rows[] = [
                'project' => $p->name,
                'due' => $s['due'] ? Carbon::parse($s['due']) : null,
                'baseline' => $s['baseline'],
                'baseline_due' => $s['baseline_due'] ? Carbon::parse($s['baseline_due']) : null,
                'forecast' => $s['forecast'] ? Carbon::parse($s['forecast']) : null,
                'days_late' => $s['days_late'],
                'slip' => self::slip($s['baseline_due'], $s['forecast']),
                'critical' => $s['critical'],
                'broken' => $s['conflicts'],
                'spi' => $money ? ($facts->money($p)['ev']['spi'] ?? null) : null,
            ];
            $links[] = route('projects.schedule', $p);
        }

        return ['rows' => $rows, 'notes' => $hidden ? ['SPI is earned value, so it shows only on projects whose money you may see; '.self::count($hidden, 'project').' left blank.'] : [], 'links' => $links];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array{rows: list<array<string, mixed>>, notes: list<string>, links: list<string|null>}
     */
    private function budget(Collection $projects, User $viewer): array
    {
        $rows = [];
        $links = [];
        [$hidden, $empty] = [0, 0];
        $facts = app(ProjectFacts::class);
        $facts->prime($projects);
        foreach ($projects as $p) {
            if (! $p->moneyVisibleTo($viewer)) {
                $hidden++;

                continue;
            }
            $m = $facts->money($p);
            if ($m === null) {
                $empty++;

                continue;
            }
            [$t, $ev] = [$m['total'], $m['ev']];
            $rows[] = [
                'project' => $p->name, 'budget' => $t['budget'], 'actual' => $t['actual'], 'committed' => $t['open'], 'forecast_cost' => $t['forecast'],
                'variance' => $t['variance'], 'used' => $t['used'], 'pv' => $ev['pv'], 'ev' => $ev['ev'], 'ac' => $ev['ac'], 'sv' => $ev['sv'], 'cv' => $ev['cv'],
                'spi' => $ev['spi'], 'cpi' => $ev['cpi'], 'eac' => $ev['eac'], 'etc' => $ev['etc'], 'vac' => $ev['vac'], 'tcpi' => $ev['tcpi'], 'coverage' => $ev['coverage'],
            ];
            $links[] = route('projects.money', $p);
        }
        $notes = array_values(array_filter([
            $hidden ? self::count($hidden, 'project').' whose money you do not see '.($hidden === 1 ? 'is' : 'are').' left out.' : null,
            $empty ? self::count($empty, 'project').' with no budget yet '.($empty === 1 ? 'is' : 'are').' left out.' : null,
            'Earned value: PV from the latest baseline\'s dates, EV by the 0/50/100 rule over tasks weighted by estimate, AC from the ledger; EAC is the budget divided by CPI.',
        ]));

        return ['rows' => $rows, 'notes' => $notes, 'links' => $links];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array{rows: list<array<string, mixed>>, notes: list<string>, links: list<string|null>}
     */
    private function risks(Collection $projects): array
    {
        $items = [];
        $today = Carbon::today();
        foreach ($projects as $p) {
            $matrix = RiskMatrix::forProject($p);
            foreach ($p->risks()->whereIn('status', ProjectRisk::LIVE)->with('owner:id,name')->get() as $r) {
                $items[] = [[
                    'project' => $p->name, 'ref' => $r->ref(), 'title' => $r->title, 'category' => ucfirst((string) $r->category),
                    'status' => ProjectRisk::STATUSES[$r->status] ?? $r->status, 'owner' => $r->owner?->name, 'probability' => $r->probability, 'impact' => $r->impact,
                    'inherent' => $r->score(), 'score' => $r->currentScore(), 'band' => ucfirst($r->band($matrix)), 'escalated' => $r->escalated_at !== null,
                    'review' => $r->review_date?->copy(), 'review_overdue' => $r->reviewOverdue($today), 'raised' => $r->created_at->copy()->startOfDay(),
                ], route('projects.risks.show', [$p, $r]), self::BANDS[$r->band($matrix)] ?? 9];
            }
        }
        usort($items, fn ($a, $b) => [$a[2], -$a[0]['score'], $a[0]['project']] <=> [$b[2], -$b[0]['score'], $b[0]['project']]);

        return ['rows' => array_column($items, 0), 'notes' => ['Closed risks and risks that became issues are not listed.'], 'links' => array_column($items, 1)];
    }

    private const BANDS = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

    /**
     * @param  Collection<int, Project>  $projects
     * @return array{rows: list<array<string, mixed>>, notes: list<string>, links: list<string|null>}
     */
    private function issues(Collection $projects): array
    {
        $items = [];
        $today = Carbon::today();
        foreach ($projects as $p) {
            foreach ($p->issues()->whereIn('status', ProjectIssue::OPEN)->with('owner:id,name')->get() as $i) {
                $items[] = [[
                    'project' => $p->name, 'ref' => $i->ref(), 'title' => $i->title, 'category' => ucfirst((string) $i->category),
                    'severity' => ProjectIssue::SEVERITIES[$i->severity] ?? $i->severity, 'priority' => ucfirst($i->priority),
                    'status' => ProjectIssue::STATUSES[$i->status] ?? $i->status, 'owner' => $i->owner?->name, 'raised' => $i->created_at->copy()->startOfDay(),
                    'target' => $i->target_date?->copy(), 'late' => $i->late($today), 'days_open' => (int) $i->created_at->copy()->startOfDay()->diffInDays($today),
                    'escalated' => $i->escalated_at !== null,
                ], route('projects.issues.show', [$p, $i]), -$i->weight()];
            }
        }
        usort($items, fn ($a, $b) => [$a[2], -$a[0]['days_open']] <=> [$b[2], -$b[0]['days_open']]);

        return ['rows' => array_column($items, 0), 'notes' => ['Resolved and closed issues are not listed.'], 'links' => array_column($items, 1)];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array{rows: list<array<string, mixed>>, notes: list<string>, links: list<string|null>}
     */
    private function tasks(Collection $projects, Carbon $from, Carbon $to): array
    {
        $rows = [];
        $links = [];
        $today = Carbon::today();
        [$start, $end] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        foreach ($projects as $p) {
            $tasks = $p->tasks()->with('predecessors:id,project_id,status')->get(['id', 'project_id', 'status', 'due_date', 'completed_at', 'created_at', 'estimate_minutes']);
            $done = $tasks->where('status', 'done');
            $timed = $done->filter(fn (ProjectTask $t) => $t->due_date !== null && $t->completed_at !== null);
            $rows[] = [
                'project' => $p->name,
                'tasks' => $tasks->count(),
                'open' => $tasks->count() - $done->count(),
                'done' => $done->count(),
                'done_in_period' => $done->filter(fn (ProjectTask $t) => $t->completed_at?->betweenIncluded($start, $end))->count(),
                'added_in_period' => $tasks->filter(fn (ProjectTask $t) => $t->created_at?->betweenIncluded($start, $end))->count(),
                'overdue' => $tasks->filter(fn (ProjectTask $t) => $t->status !== 'done' && $t->due_date !== null && $t->due_date->lt($today))->count(),
                'blocked' => $tasks->filter(fn (ProjectTask $t) => $t->isBlocked())->count(),
                'no_estimate' => $tasks->filter(fn (ProjectTask $t) => $t->status !== 'done' && $t->estimate_minutes === null)->count(),
                'done_pct' => $tasks->isEmpty() ? null : (int) round($done->count() / $tasks->count() * 100),
                'on_time_pct' => $timed->isEmpty() ? null : (int) round($timed->filter(fn (ProjectTask $t) => $t->completed_at->copy()->startOfDay()->lte($t->due_date))->count() / $timed->count() * 100),
            ];
            $links[] = route('projects.work', $p);
        }

        return ['rows' => $rows, 'notes' => ['On time: done tasks that had a due date and were finished on or before it.'], 'links' => $links];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array{rows: list<array<string, mixed>>, notes: list<string>, links: list<string|null>}
     */
    private function milestones(Collection $projects): array
    {
        $rows = [];
        $links = [];
        foreach ($projects as $p) {
            $schedule = new Schedule($p);
            $base = Schedule::baselineMap($p->baselines()->first());
            foreach ($schedule->milestones as $m) {
                /** @var Milestone $m */
                $tasks = $schedule->tasks->where('milestone_id', $m->id);
                $done = $m->status === 'completed';
                $finished = $done ? $tasks->max(fn (ProjectTask $t) => $t->completed_at)?->copy()->startOfDay() : null;
                $forecast = $done ? null : $schedule->milestoneForecast($m)?->copy();
                $baseDue = isset($base['milestone'][$m->id]) ? $base['milestone'][$m->id]->due_date?->copy() : null;
                $against = $baseDue ?? $m->due_date;
                $lands = $finished ?? $forecast;
                $rows[] = [
                    'project' => $p->name, 'milestone' => $m->title, 'status' => ucfirst(str_replace('_', ' ', $m->status)),
                    'baseline_due' => $baseDue, 'due' => $m->due_date?->copy(), 'forecast' => $forecast, 'finished' => $finished,
                    'slip' => $against && $lands ? (int) $against->diffInDays($lands, false) : null,
                    'on_time' => $done && $finished && $m->due_date ? $finished->lte($m->due_date) : null,
                    'done' => $tasks->where('status', 'done')->count(), 'tasks' => $tasks->count(),
                ];
                $links[] = route('projects.schedule', $p);
            }
        }

        return ['rows' => $rows, 'notes' => ['Slip: days between the baseline date (or the due date without a baseline) and when the milestone finished or lands as planned now; negative is early. Finished: the day its last task was done.'], 'links' => $links];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array{rows: list<array<string, mixed>>, notes: list<string>, links: list<string|null>}
     */
    private function time(Collection $projects, User $viewer, Carbon $from, Carbon $to): array
    {
        $report = app(TimeReport::class);
        $rows = [];
        $links = [];
        $totalsOnly = 0;
        foreach ($projects as $p) {
            $all = $report->entries($p, $from, $to, false);
            if ($all->isEmpty()) {
                continue;
            }
            $approved = $report->entries($p, $from, $to, true);
            $row = fn (string $person, Collection $e, Collection $ok) => [
                'project' => $p->name, 'person' => $person, 'hours' => (int) $e->sum('minutes'), 'billable' => (int) $e->where('billable', true)->sum('minutes'),
                'approved' => (int) $ok->sum('minutes'), 'entries' => $e->count(),
            ];
            if ($viewer->can('seeTimeDetail', $p)) {
                foreach ($all->groupBy('user_id') as $userId => $mine) {
                    $rows[] = $row((string) $mine->first()->user->name, $mine, $approved->where('user_id', $userId));
                    $links[] = route('projects.time', $p);
                }
            } else {
                $totalsOnly++;
                $rows[] = $row('Everyone', $all, $approved);
                $links[] = route('projects.time', $p);
            }
        }
        $notes = ['Approved: time in weeks whose timesheet was approved. Running timers are not counted.'];
        if ($totalsOnly) {
            array_unshift($notes, 'Time by person shows only on projects you run; '.self::count($totalsOnly, 'project').' show the total for everyone.');
        }

        return ['rows' => $rows, 'notes' => $notes, 'links' => $links];
    }

    /** @return array{rows: list<array<string, mixed>>, notes: list<string>, links: list<string|null>} */
    private function utilisation(Team $team, User $viewer): array
    {
        $oversees = Workload::oversees($viewer, $team);
        $people = $oversees ? $team->allUsers()->sortBy('name')->values() : collect([$viewer]);
        $rows = [];
        foreach (app(Workload::class)->forPeople($team, $people, $viewer) as $r) {
            $weeks = array_values($r['weeks']);
            $rows[] = [
                'person' => $r['user']->name, 'capacity' => $r['capacity'], 'this_week' => $weeks[0]['minutes'] ?? 0,
                'peak' => max(array_column($weeks, 'minutes') ?: [0]), 'weeks_over' => count(array_filter($weeks, fn ($w) => $w['minutes'] > $r['capacity'])),
                'last_week' => $r['lastWeek'], 'utilisation' => $r['capacity'] > 0 ? (int) round($r['lastWeek'] / $r['capacity'] * 100) : null,
                'overdue' => $r['overdue'], 'undated' => $r['undated'], 'no_estimate' => $r['noEstimate'],
            ];
        }
        $notes = ['Planned work is each open task\'s remaining estimate spread over the working days to its due date, as on the Workload page. It covers every project, whatever the filters.'];
        if (! $oversees) {
            array_unshift($notes, 'Only the business owner and admins see everyone; this shows you.');
        }

        return ['rows' => $rows, 'notes' => $notes, 'links' => array_fill(0, count($rows), route('workload.index'))];
    }

    private static function health(string $rating): string
    {
        return ['on_track' => 'On track', 'at_risk' => 'At risk', 'off_track' => 'Off track'][$rating] ?? $rating;
    }

    private static function slip(?string $baselineDue, ?string $forecast): ?int
    {
        return $baselineDue && $forecast ? (int) Carbon::parse($baselineDue)->diffInDays(Carbon::parse($forecast), false) : null;
    }

    private static function count(int $n, string $word): string
    {
        return $n.' '.str($word)->plural($n);
    }

    /** A value as people read it, on the page and in the PDF. */
    public static function show(mixed $value, string $type): string
    {
        if ($value === null) {
            return '–';
        }

        return match ($type) {
            'money' => Money::rand((int) $value),
            'date' => $value instanceof Carbon ? $value->format('j M Y') : (string) $value,
            'percent' => $value.'%',
            'ratio' => number_format((float) $value, 2),
            'hours' => self::hours((int) $value),
            'days' => (string) $value,
            'bool' => $value ? 'Yes' : 'No',
            'int' => number_format((int) $value, 0, '.', ' '),
            default => (string) $value,
        };
    }

    /** A value for a spreadsheet: numbers as numbers (rand, hours), dates as ISO dates. */
    public static function export(mixed $value, string $type): string|int|float|null
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'money' => round(((int) $value) / 100, 2),
            'hours' => round(((int) $value) / 60, 2),
            'date' => $value instanceof Carbon ? $value->toDateString() : (string) $value,
            'ratio' => round((float) $value, 2),
            'int', 'percent', 'days' => (int) $value,
            'bool' => $value ? 'Yes' : 'No',
            default => (string) $value,
        };
    }

    /** The header of an exported column: its label, with the unit numbers are in. */
    public static function exportLabel(string $label, string $type): string
    {
        return $label.match ($type) {
            'money' => ' (R)', 'hours' => ' (hours)', 'percent' => ' (%)', 'days' => ' (days)', default => '',
        };
    }

    private static function hours(int $minutes): string
    {
        $h = $minutes / 60;

        return (floor($h) == $h ? (string) (int) $h : number_format($h, 1)).' h';
    }
}
