<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The business's projects as one ruled list: status, the next dated milestone, progress, overdue
 * work and the due date. The team scope on Project limits it to the current business.
 */
class DashboardController extends Controller
{
    public const STATUSES = ['planning' => 'Planning', 'active' => 'Active', 'on_hold' => 'On hold', 'pending_closure_approval' => 'Closing', 'completed' => 'Completed'];

    public function __invoke(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $statusFilter = (string) $request->query('status', '');
        $today = Carbon::today()->toDateString();

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

        return view('dashboard', [
            'projects' => $projects,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'statuses' => self::STATUSES,
            'archivedCount' => $statusFilter === 'archived' ? null : Project::query()->whereNotNull('archived_at')->count(),
            'today' => Carbon::today(),
        ]);
    }
}
