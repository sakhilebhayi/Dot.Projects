<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Each project's facts and money for the pages that compare many projects (R-34 P13e: the portfolio, reports and
 * dashboards). Working them out costs about 35 ms a project (the schedule, the summary, the ledger, earned
 * value), so they are kept, per project, under a stamp of everything they are read from:
 *
 *  - the project row, and its tasks, milestones, dependencies (both ends, including tasks in other projects that
 *    move this one's dates), baselines, risks, issues, changes, decisions, gates and their checks, actions, status
 *    reports, budget lines and revisions, costs and time entries; each as its count and latest change, so an
 *    edit, an addition and a deletion all change it;
 *  - the business's hourly rates (labour cost), as their count, sum and latest date;
 *  - today's date, since lateness depends on it;
 *  - each project's change counter, which every record above moves on when it is saved or deleted
 *    (App\Models\Concerns\BumpsProjectFacts), and the business's counter, moved by rates and timesheet
 *    approvals; so two changes within one second, which share a timestamp, are still told apart.
 *
 * One grouped query per kind of record covers every project on the page, so the stamps cost the same for 3
 * projects as for 300, and a project is worked out again only when something it is read from has changed. Kept
 * entries also expire after TTL seconds, so a write that slipped past every stamp heals by itself. A project's
 * own pages and drafting a status report always work their facts out fresh.
 */
final class ProjectFacts
{
    /** Bump when the shape of what is kept changes, so entries from older code are never read. */
    private const VERSION = 1;

    public const TTL = 600;

    /** @var array<int, string> project id => stamp, for the projects primed in this request */
    private array $stamps = [];

    /** Moves the change counters of these projects on. */
    public static function bump(int ...$projectIds): void
    {
        foreach (array_unique($projectIds) as $id) {
            self::increment('pfacts:ver:'.$id);
        }
    }

    /** Moves a business's counter on: something every project's money reads from changed (rates, approved time). */
    public static function bumpTeam(int $teamId): void
    {
        self::increment('pfacts:team:'.$teamId);
    }

    private static function increment(string $key): void
    {
        // Counters outlive the kept facts; add first, because some stores will not increment a missing key.
        Cache::add($key, 0, now()->addDays(30));
        Cache::increment($key);
    }

    /**
     * Works out the stamps of a set of projects in one go, before their facts are asked for.
     *
     * @param  Collection<int, Project>  $projects
     */
    public function prime(Collection $projects): void
    {
        $ids = $projects->pluck('id')->map(fn ($id) => (int) $id)->reject(fn ($id) => isset($this->stamps[$id]))->values()->all();
        if ($ids === []) {
            return;
        }
        $parts = array_fill_keys($ids, []);
        $collect = function ($rows) use (&$parts) {
            foreach ($rows as $r) {
                $parts[(int) $r->pid][] = $r->n.'@'.$r->at;
            }
        };
        $grouped = fn (string $table, string $key = 'project_id') => DB::table($table)->whereIn($key, $ids)
            ->groupBy($key)->selectRaw($key.' as pid, count(*) as n, max(updated_at) as at')->get();

        $collect(DB::table('projects')->whereIn('id', $ids)->selectRaw('id as pid, 1 as n, updated_at as at')->get());
        foreach (['project_tasks', 'milestones', 'project_baselines', 'project_risks', 'project_issues', 'project_changes', 'project_decisions',
            'project_gates', 'project_actions', 'project_reports', 'budget_lines', 'cost_items', 'time_entries'] as $table) {
            $collect($grouped($table));
        }
        $collect(DB::table('gate_checks')->join('project_gates', 'project_gates.id', '=', 'gate_checks.project_gate_id')->whereIn('project_gates.project_id', $ids)
            ->groupBy('project_gates.project_id')->selectRaw('project_gates.project_id as pid, count(*) as n, max(gate_checks.updated_at) as at')->get());
        $collect(DB::table('budget_revisions')->join('budget_lines', 'budget_lines.id', '=', 'budget_revisions.budget_line_id')->whereIn('budget_lines.project_id', $ids)
            ->groupBy('budget_lines.project_id')->selectRaw('budget_lines.project_id as pid, count(*) as n, max(budget_revisions.updated_at) as at')->get());
        // Dependencies from both ends, with the latest change to the task at the other end, wherever it is.
        foreach ([['s', 'p'], ['p', 's']] as [$here, $there]) {
            $collect(DB::table('task_dependencies as d')->join('project_tasks as s', 's.id', '=', 'd.successor_id')->join('project_tasks as p', 'p.id', '=', 'd.predecessor_id')
                ->whereIn($here.'.project_id', $ids)->groupBy($here.'.project_id')
                ->selectRaw($here.'.project_id as pid, count(*) as n, max('.$there.'.updated_at) as at')->get());
        }
        $teams = $projects->pluck('team_id')->unique()->all();
        $counters = Cache::many([...array_map(fn ($id) => 'pfacts:ver:'.$id, $ids), ...array_map(fn ($t) => 'pfacts:team:'.$t, $teams)]);
        $sheets = DB::table('timesheets')->whereIn('team_id', $teams)->groupBy('team_id')
            ->selectRaw('team_id, count(*) as n, max(updated_at) as at')->get()->keyBy('team_id');
        $rates = DB::table('person_rates')->whereIn('team_id', $teams)->groupBy('team_id')
            ->selectRaw('team_id, count(*) as n, sum(hourly_cents) as total, max(effective_from) as at')->get()->keyBy('team_id');
        $today = Carbon::today()->toDateString();

        foreach ($projects as $p) {
            $r = $rates->get($p->team_id);
            $sh = $sheets->get($p->team_id);
            $this->stamps[(int) $p->id] = md5(implode('|', $parts[(int) $p->id] ?? [])
                .'|rates:'.($r ? $r->n.':'.$r->total.':'.$r->at : '-').'|sheets:'.($sh ? $sh->n.':'.$sh->at : '-')
                .'|v:'.($counters['pfacts:ver:'.$p->id] ?? 0).':'.($counters['pfacts:team:'.$p->team_id] ?? 0).'|'.$today);
        }
    }

    private function stamp(Project $project): string
    {
        if (! isset($this->stamps[$project->id])) {
            $this->prime(collect([$project]));
        }

        return $this->stamps[$project->id];
    }

    /** @return array<string, mixed> StatusReports::facts() for the period, kept until anything it reads changes */
    public function facts(Project $project, Carbon $from, Carbon $to): array
    {
        $key = 'pfacts:v'.self::VERSION.':'.$project->id.':'.$from->toDateString().':'.$to->toDateString().':'.$this->stamp($project);

        return Cache::remember($key, self::TTL, fn () => app(StatusReports::class)->facts($project, $from, $to));
    }

    /**
     * The project's money, kept the same way: the ledger's totals and earned value, or null without a budget.
     * Who may see it is decided by the caller, every time.
     *
     * @return array{total: array<string, int>, ev: array<string, mixed>}|null
     */
    public function money(Project $project): ?array
    {
        $key = 'pmoney:v'.self::VERSION.':'.$project->id.':'.$this->stamp($project);

        return Cache::remember($key, self::TTL, function () use ($project) {
            if (! $project->budgetLines()->exists()) {
                return null;
            }
            $total = app(CostLedger::class)->ledger($project)['total'];

            return ['total' => $total, 'ev' => app(EarnedValue::class)->compute($project, null, $total)];
        });
    }

    /** Forgets the stamps worked out in this request (after a write, or between test steps). */
    public function forget(): void
    {
        $this->stamps = [];
    }
}
