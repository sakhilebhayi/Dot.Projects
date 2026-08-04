<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->name('ecosystem.auth');

Route::get('/', fn () => view('welcome'));

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function (\Illuminate\Http\Request $request) {
        $search = trim((string) $request->query('search', ''));
        $statusFilter = $request->query('status', '');

        // No explicit currentTeam->projects() relation needed as a scoping
        // mechanism: Project's HasTeamScope trait applies the current-team
        // filter automatically to every query against this model.
        $projects = \App\Models\Project::query()
            ->select(['id', 'team_id', 'owner_id', 'name', 'description', 'status', 'start_date', 'due_date', 'created_at', 'updated_at'])
            ->withCount(['tasks', 'tasks as done_tasks_count' => fn ($q) => $q->where('status', 'done')])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($statusFilter !== '', fn ($q) => $q->where('status', $statusFilter))
            ->latest()
            ->get();

        return view('dashboard', compact('projects', 'search', 'statusFilter'));
    })->name('dashboard');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');

    Route::get('/projects/create', fn () => view('projects.create'))->name('projects.create');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
});
