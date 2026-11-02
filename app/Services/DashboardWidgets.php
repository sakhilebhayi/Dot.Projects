<?php

namespace App\Services;

use App\Models\DashboardWidget;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\SavedReport;
use App\Models\Team;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * What a dashboard can hold, and each widget's contents for one reader. Every widget is built from
 * a service a page already uses (Reporting, PortfolioOverview, Workload, TimeTracking), through the
 * same scopes and permissions, so a shared dashboard never shows anyone more than they may see.
 */
final class DashboardWidgets
{
    /** @var array<string, array{label: string, about: string, portfolio: bool}> */
    public const KINDS = [
        'report' => ['label' => 'A report', 'about' => 'The first rows of a saved report or one of the standard reports.', 'portfolio' => false],
        'health' => ['label' => 'Health', 'about' => 'How many projects are on track, at risk and off track, and which need intervention.', 'portfolio' => true],
        'roadmap' => ['label' => 'Roadmap', 'about' => 'Six months of milestones for every project on one axis.', 'portfolio' => true],
        'risks' => ['label' => 'Major risks', 'about' => 'Live risks rated high or critical, worst first.', 'portfolio' => true],
        'links' => ['label' => 'Work between projects', 'about' => 'Which projects wait on which, and what is still held up.', 'portfolio' => true],
        'my_work' => ['label' => 'Your work', 'about' => 'Your open tasks that are overdue or due in the next two weeks.', 'portfolio' => false],
        'my_time' => ['label' => 'Your time this week', 'about' => 'Hours logged this week against your capacity, and your timesheet.', 'portfolio' => false],
    ];

    public const ROWS = [5, 10, 20];

    /**
     * Checks a widget's settings against its kind and the reader's business: an unknown kind, a
     * portfolio from elsewhere or a saved report they cannot read is refused.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function clean(string $kind, array $raw, Team $team, User $by): array
    {
        if (! isset(self::KINDS[$kind])) {
            throw ValidationException::withMessages(['widget' => 'Choose what the widget shows.']);
        }
        $config = [];
        if (self::KINDS[$kind]['portfolio'] && ($raw['portfolio'] ?? '') !== '') {
            if (! Portfolio::query()->where('team_id', $team->id)->whereKey((int) $raw['portfolio'])->exists()) {
                throw ValidationException::withMessages(['widget' => 'Choose a portfolio in this business.']);
            }
            $config['portfolio'] = (string) (int) $raw['portfolio'];
        }
        if ($kind === 'report') {
            $source = (string) ($raw['source'] ?? '');
            if (str_starts_with($source, 'saved:')) {
                $saved = SavedReport::query()->where('team_id', $team->id)->find((int) substr($source, 6));
                if ($saved === null || ! $saved->readableBy($by)) {
                    throw ValidationException::withMessages(['widget' => 'Choose a report you can open.']);
                }
                $config['saved'] = $saved->id;
            } elseif (isset(Reporting::REPORTS[$source])) {
                $config['type'] = $source;
            } else {
                throw ValidationException::withMessages(['widget' => 'Choose a report.']);
            }
            $rows = (int) ($raw['rows'] ?? 5);
            $config['rows'] = in_array($rows, self::ROWS, true) ? $rows : 5;
        }

        return $config;
    }

    /** The widget's heading: its own title, or what it shows. */
    public static function heading(DashboardWidget $w, ?string $reportName = null): string
    {
        return $w->title ?: ($reportName ?? self::KINDS[$w->kind]['label'] ?? 'Widget');
    }

    /**
     * The widget's contents for this reader.
     *
     * @return array<string, mixed>
     */
    public function data(DashboardWidget $w, Team $team, User $viewer): array
    {
        $c = $w->config;
        $filters = Reporting::normalise('executive', ['filters' => ['portfolio' => $c['portfolio'] ?? '']])['filters'];
        $scope = isset($c['portfolio']) ? Portfolio::query()->where('team_id', $team->id)->whereKey((int) $c['portfolio'])->value('name') : null;

        if (isset($c['portfolio']) && $scope === null) {
            return ['gone' => true];
        }

        return match ($w->kind) {
            'report' => $this->report($c, $team, $viewer),
            'health' => ['scope' => $scope] + $this->health($team, $viewer, $filters),
            'roadmap' => ['scope' => $scope, 'roadmap' => app(PortfolioOverview::class)->roadmap(app(Reporting::class)->projects($team, $filters), Carbon::today())],
            'risks' => ['scope' => $scope, 'risks' => app(PortfolioOverview::class)->majorRisks(app(Reporting::class)->projects($team, $filters), 6)],
            'links' => ['scope' => $scope, 'links' => app(PortfolioOverview::class)->links(app(Reporting::class)->projects($team, $filters))],
            'my_work' => $this->myWork($team, $viewer),
            'my_time' => $this->myTime($team, $viewer),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function report(array $c, Team $team, User $viewer): array
    {
        $name = null;
        $url = null;
        if (isset($c['saved'])) {
            $saved = SavedReport::query()->where('team_id', $team->id)->find((int) $c['saved']);
            if ($saved === null || ! $saved->readableBy($viewer) || ! isset(Reporting::REPORTS[$saved->type])) {
                return ['missing' => true, 'name' => null];
            }
            [$type, $config, $name, $url] = [$saved->type, Reporting::normalise($saved->type, $saved->config), $saved->name, route('reporting.index', ['saved' => $saved->id])];
        } else {
            $type = (string) ($c['type'] ?? '');
            if (! isset(Reporting::REPORTS[$type])) {
                return ['missing' => true, 'name' => null];
            }
            [$config, $url] = [Reporting::normalise($type, []), route('reporting.index', ['type' => $type])];
        }
        $result = app(Reporting::class)->run($type, $team, $viewer, $config);
        $rows = (int) ($c['rows'] ?? 5);
        $def = Reporting::REPORTS[$type];

        return [
            'missing' => false,
            'name' => $name ?? $def['label'],
            'url' => $url,
            'columns' => array_map(fn ($k) => ['key' => $k, 'label' => $def['columns'][$k][0], 'type' => $def['columns'][$k][1]], array_slice($config['columns'], 0, 5)),
            'rows' => array_slice($result['rows'], 0, $rows),
            'links' => array_slice($result['links'], 0, $rows),
            'total' => count($result['rows']),
            'notes' => $result['notes'],
        ];
    }

    /**
     * @param  array{scope: string, portfolio: string, projects: list<int>, period: string, from: string, to: string}  $filters
     * @return array<string, mixed>
     */
    private function health(Team $team, User $viewer, array $filters): array
    {
        $o = app(PortfolioOverview::class)->build(app(Reporting::class)->projects($team, $filters), $viewer);

        return ['counts' => $o['counts'], 'total' => count($o['rows']), 'intervene' => array_slice($o['intervene'], 0, 5)];
    }

    /** @return array<string, mixed> */
    private function myWork(Team $team, User $viewer): array
    {
        $today = Carbon::today();
        $visible = Project::query()->where('team_id', $team->id)->whereNull('archived_at')->pluck('id');
        $tasks = ProjectTask::query()->whereIn('project_id', $visible)->where('assignee_id', $viewer->id)->where('status', '!=', 'done')
            ->whereNotNull('due_date')->whereDate('due_date', '<=', $today->copy()->addDays(14)->toDateString())
            ->with(['project:id,name,team_id,visibility,owner_id', 'predecessors:id,project_id,status'])->orderBy('due_date')->limit(30)->get();

        return [
            'overdue' => $tasks->filter(fn (ProjectTask $t) => $t->due_date->lt($today))->count(),
            'tasks' => $tasks->take(8)->all(),
            'more' => max(0, $tasks->count() - 8),
            'open' => ProjectTask::query()->whereIn('project_id', $visible)->where('assignee_id', $viewer->id)->where('status', '!=', 'done')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function myTime(Team $team, User $viewer): array
    {
        $monday = TimeTracking::weekStart(Carbon::today());
        $minutes = (int) TimeEntry::query()->where('team_id', $team->id)->where('user_id', $viewer->id)->whereNull('running_since')
            ->whereDate('work_date', '>=', $monday->toDateString())->whereDate('work_date', '<=', $monday->copy()->addDays(6)->toDateString())->sum('minutes');
        $running = TimeEntry::query()->where('team_id', $team->id)->where('user_id', $viewer->id)->whereNotNull('running_since')->exists();
        $status = Timesheet::query()->where('team_id', $team->id)->where('user_id', $viewer->id)->whereDate('week_start', $monday->toDateString())->value('status') ?? 'open';

        return [
            'minutes' => $minutes,
            'capacity' => app(Workload::class)->capacities($team)[$viewer->id]['minutes'] ?? Workload::DEFAULT_MINUTES,
            'running' => $running,
            'status' => Timesheet::STATUSES[$status] ?? $status,
            'week' => $monday,
        ];
    }
}
