<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\ProjectFacts;
use App\Services\ProjectSummary;
use App\Services\StatusReports;
use App\Services\Workload;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Where a person arrives after signing in through InfoDot (R-50): the original logo, a welcome from
 * their own name and business, the one action their role can take, the date line of a real project,
 * the work waiting on them, and then the business's projects as one ruled list (status, the next
 * dated milestone, progress, overdue work, due date). Every figure is counted from records; the
 * team and private scopes on Project limit all of it to what this person may see.
 */
class DashboardController extends Controller
{
    public const STATUSES = ['planning' => 'Planning', 'active' => 'Active', 'on_hold' => 'On hold', 'pending_closure_approval' => 'Closing', 'completed' => 'Completed'];

    public const RATED_ROWS = 100;

    public const PANEL_ROWS = 60;

    public const FINDING_ROWS = 12;

    public function __invoke(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $statusFilter = (string) $request->query('status', '');
        $today = Carbon::today()->toDateString();
        $user = $request->user();
        $canCreate = $user->can('create', Project::class);

        $projects = Project::query()
            ->withCount([
                'tasks',
                'tasks as done_tasks_count' => fn ($q) => $q->where('status', 'done'),
                'tasks as overdue_tasks_count' => fn ($q) => $q->where('status', '!=', 'done')->whereNotNull('due_date')->whereDate('due_date', '<', $today),
            ])
            ->with(['milestones' => fn ($q) => $q->whereNotNull('due_date')->orderBy('due_date')])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($statusFilter === 'archived', fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->when($statusFilter !== '' && array_key_exists($statusFilter, self::STATUSES), fn ($q) => $q->where('status', $statusFilter))
            ->orderByRaw("case status when 'completed' then 1 else 0 end")
            ->latest()
            ->get();

        // The arrival block reads the whole business, whatever the list below is filtered to.
        $open = Project::query()->whereNull('archived_at')->where('status', '!=', 'completed');
        $openCount = (clone $open)->count();
        $lateCount = (clone $open)->where(fn ($q) => $q
            ->whereDate('due_date', '<', $today)
            ->orWhereHas('tasks', fn ($t) => $t->where('status', '!=', 'done')->whereNotNull('due_date')->whereDate('due_date', '<', $today)))->count();

        // Open work given to this person, soonest first; undated work after the dated.
        $mine = ProjectTask::query()
            ->where('assignee_id', $user->id)->where('status', '!=', 'done')
            ->whereHas('project', fn ($q) => $q->whereNull('archived_at'))
            ->with('project:id,team_id,owner_id,name,visibility')
            ->orderByRaw('case when due_date is null then 1 else 0 end')->orderBy('due_date')->orderBy('id');

        // Attention order (R-51): health first (Off track, At risk, On track, then the rest), then days late. Completed and
        // archived projects are never rated; a facts failure falls back to the stored status and counts, never a blank page.
        $projects = $projects->values();
        $ratings = $this->ratings($projects, $user, $today);
        $projects = $projects
            ->map(fn (Project $p, int $i) => [$p, $i])
            ->sort(function (array $a, array $b) use ($ratings) {
                $ra = $ratings[$a[0]->id] ?? null;
                $rb = $ratings[$b[0]->id] ?? null;

                return [self::rank($ra['health'] ?? null), -($ra['late'] ?? 0), $a[1]] <=> [self::rank($rb['health'] ?? null), -($rb['late'] ?? 0), $b[1]];
            })
            ->map(fn (array $pair) => $pair[0])
            ->values();

        // The side panel is drawn for the first rows only; the rest stay links. A project in the address picks the row, if it is loaded.
        $panelIds = $projects->take(self::PANEL_ROWS)->pluck('id')->all();
        $asked = (int) $request->query('project', 0);
        $selectedId = in_array($asked, $panelIds, true) ? $asked : ($panelIds[0] ?? null);
        $findings = [];
        foreach ($projects->take(self::FINDING_ROWS) as $p) {
            if (($ratings[$p->id]['health'] ?? null) !== null || $p->status === 'completed') {
                $findings[$p->id] = (new ProjectSummary($p))->finding()[0];
            }
        }

        $overseer = $user->currentTeam !== null && Workload::oversees($user, $user->currentTeam);

        return view('dashboard', [
            'ratings' => $ratings,
            'findings' => $findings,
            'panelIds' => $panelIds,
            'selectedId' => $selectedId,
            // A member whose own work is the reason they came sees it first on a phone (not an overseer, not a manager anywhere).
            'memberFirst' => ! $canCreate ? false : (! $overseer && (clone $mine)->count() > 0 && ! DB::table('project_members')->where('user_id', $user->id)->where('role', 'manager')->whereIn('project_id', Project::query()->select('id'))->exists()),
            'firstName' => Str::of((string) $user->name)->trim()->before(' ')->toString(),
            'canCreate' => $canCreate,
            // One primary action per role: people who may create start a project; read-only viewers review the portfolio.
            'primary' => $canCreate
                ? ['route' => 'projects.create', 'label' => 'Start a project', 'icon' => 'add']
                : ['route' => 'portfolio.index', 'label' => 'Review the portfolio', 'icon' => 'account_tree'],
            'hasAnyProject' => $openCount > 0 || Project::query()->exists(),
            'openCount' => $openCount,
            'lateCount' => $lateCount,
            'mineCount' => (clone $mine)->count(),
            'mine' => $mine->limit(5)->get(),
            'projects' => $projects,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'statuses' => self::STATUSES,
            'archivedCount' => $statusFilter === 'archived' ? null : Project::query()->whereNotNull('archived_at')->count(),
            'today' => Carbon::today(),
        ]);
    }

    /**
     * Health per open, unarchived project in the list (the rating status reports use, worked out from records today).
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, array{health: ?string, reasons: list<string>, due: ?string, forecast: ?string, late: int, risks: int, critical: int, failed: bool}>
     */
    private function ratings($projects, $user, string $today): array
    {
        $open = $projects->filter(fn (Project $p) => $p->archived_at === null && $p->status !== 'completed')->take(self::RATED_ROWS);
        $out = [];
        $facts = app(ProjectFacts::class);
        $reports = app(StatusReports::class);
        $now = Carbon::parse($today);
        try {
            $facts->prime($open);
        } catch (\Throwable $e) {
            report($e);
        }
        foreach ($open as $p) {
            try {
                $f = $facts->facts($p, $now->copy()->subDays(6), $now);
                $h = $reports->suggest($f);
                $out[$p->id] = ['health' => $h['rating'], 'reasons' => $h['reasons'], 'due' => $f['schedule']['due'], 'forecast' => $f['schedule']['forecast'], 'late' => (int) $f['schedule']['days_late'], 'risks' => (int) $f['risks']['serious'], 'critical' => (int) $f['issues']['critical'], 'failed' => false];
            } catch (\Throwable $e) {
                report($e);
                $out[$p->id] = ['health' => null, 'reasons' => [], 'due' => null, 'forecast' => null, 'late' => 0, 'risks' => 0, 'critical' => 0, 'failed' => true];
            }
        }

        return $out;
    }

    private static function rank(?string $health): int
    {
        return ['off_track' => 0, 'at_risk' => 1, 'on_track' => 2][$health] ?? 3;
    }
}
