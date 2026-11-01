{{-- The project's title row and views, shared by every project view. Needs $project, $summary, $view. --}}
@php
    [$finding, $actionLabel, $actionKey] = $summary->finding();
    $statusLabel = App\Http\Controllers\DashboardController::STATUSES[$project->status] ?? Str::headline($project->status);
    $statusPill = match ($project->status) { 'pending_closure_approval' => 'pg-pill--warning', 'completed' => 'pg-pill--positive', default => '' };
@endphp
<p class="pg-crumb"><a href="{{ route('dashboard') }}">{{ $project->team?->name ?? 'Projects' }}</a> <span aria-hidden="true">/</span> <a href="{{ route('dashboard') }}">Projects</a> <span aria-hidden="true">/</span> <span class="pg-id">P-{{ str_pad((string) $project->id, 4, '0', STR_PAD_LEFT) }}</span></p>
<div class="pg-titlerow">
    <div>
        <h1 class="pg-title">{{ $project->name }}<span class="pg-pill {{ $statusPill }}">{{ $statusLabel }}</span></h1>
        <p class="pg-finding">{{ $finding }}</p>
    </div>
    @php $canAct = ! $project->isArchived() && ($actionLabel === 'Open the board' || auth()->user()->can('update', $project)); @endphp
    @if ($view === 'overview' && $canAct)
        <div class="pg-actions">
            <a href="{{ route('projects.board', $project) }}{{ $actionLabel === 'Add a milestone' ? '#add-milestone' : '' }}" class="pg-btn pg-btn--primary">{{ $actionLabel }}</a>
        </div>
    @endif
</div>
@if ($project->isArchived())
    <div class="pg-notice" role="status"><span><b>Archived {{ $project->archived_at->format('j M Y') }}.</b> Everything is kept and read-only.</span>@can('archive', $project)<a href="{{ route('projects.settings', $project) }}" class="pg-btn pg-btn--secondary pg-btn--sm">Restore it</a>@endcan</div>
@endif
<nav class="pg-views" aria-label="Project views">
    <a href="{{ route('projects.show', $project) }}" @if ($view === 'overview') aria-current="page" @endif>Overview</a>
    <a href="{{ route('projects.work', $project) }}" @if ($view === 'work') aria-current="page" @endif>Work</a>
    <a href="{{ route('projects.schedule', $project) }}" @if ($view === 'schedule') aria-current="page" @endif>Schedule</a>
    <a href="{{ route('projects.board', $project) }}" @if ($view === 'board') aria-current="page" @endif>Board</a>
    <a href="{{ route('projects.risks', $project) }}" @if ($view === 'governance') aria-current="page" @endif>Governance</a>
    <a href="{{ route('projects.settings', $project) }}" @if ($view === 'settings') aria-current="page" @endif>Settings</a>
</nav>
