<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    public function __invoke(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $statusFilter = (string) $request->query('status', '');
        $today = Carbon::today()->toDateString();
        $user = $request->user();
        $canCreate = $user->can('create', Project::class);

        $projects = Project::query()
            ->select(['id', 'team_id', 'owner_id', 'name', 'description', 'status', 'visibility', 'start_date', 'due_date', 'archived_at', 'created_at', 'updated_at'])
            ->withCount([
                'tasks',
                'tasks as done_tasks_count' => fn ($q) => $q->where('status', 'done'),
                'tasks as overdue_tasks_count' => fn ($q) => $q->where('status', '!=', 'done')->whereNotNull('due_date')->whereDate('due_date', '<', $today),
            ])
            ->with(['milestones' => fn ($q) => $q->where('status', '!=', 'completed')->whereNotNull('due_date')->orderBy('due_date')])
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

        // The product visual: the date line of the open project touched most recently that has dated milestones.
        $featured = Project::query()
            ->whereNull('archived_at')->where('status', '!=', 'completed')
            ->whereHas('milestones', fn ($q) => $q->whereNotNull('due_date'))
            ->with(['milestones' => fn ($q) => $q->whereNotNull('due_date')->orderBy('due_date')])
            ->withCount(['tasks', 'tasks as done_tasks_count' => fn ($q) => $q->where('status', 'done')])
            ->latest('updated_at')->latest('id')
            ->first();

        // Open work given to this person, soonest first; undated work after the dated.
        $mine = ProjectTask::query()
            ->where('assignee_id', $user->id)->where('status', '!=', 'done')
            ->whereHas('project', fn ($q) => $q->whereNull('archived_at'))
            ->with('project:id,team_id,owner_id,name,visibility')
            ->orderByRaw('case when due_date is null then 1 else 0 end')->orderBy('due_date')->orderBy('id');

        return view('dashboard', [
            'firstName' => Str::of((string) $user->name)->trim()->before(' ')->toString(),
            'canCreate' => $canCreate,
            // One primary action per role: people who may create start a project; read-only viewers review the portfolio.
            'primary' => $canCreate
                ? ['route' => 'projects.create', 'label' => 'Start a project', 'icon' => 'add']
                : ['route' => 'portfolio.index', 'label' => 'Review the portfolio', 'icon' => 'account_tree'],
            'infodot' => rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/'),
            'hasAnyProject' => $openCount > 0 || Project::query()->exists(),
            'openCount' => $openCount,
            'lateCount' => $lateCount,
            'featured' => $featured,
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
}
