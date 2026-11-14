{{-- One project's facts for the side panel: read-only, every figure from the loaded row and its health rating. --}}
@php
    $f = $ratings[$project->id] ?? null;
    $rated = $f && $f['health'] !== null;
    $hl = ['off_track' => ['Off track', 'pg-pill--negative'], 'at_risk' => ['At risk', 'pg-pill--warning'], 'on_track' => ['On track', 'pgd-h-on']];
    $left = $project->due_date ? (int) $today->diffInDays($project->due_date, false) : null;
    $finished = $project->status === 'completed';
@endphp
<h2><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></h2>
<p class="pgd-finding">
    @if (isset($findings[$project->id])){!! $findings[$project->id] !!}@elseif ($rated && $f['reasons']){{ implode('. ', $f['reasons']) }}.@else{{ $finished ? 'Completed.' : '' }}@endif
</p>
<dl class="pgd-facts">
    <div><dt>Health</dt><dd>
        @if ($rated)
            <span class="pg-pill {{ $hl[$f['health']][1] }}">{{ $hl[$f['health']][0] }}</span>
            @if ($f['reasons'])<small>{{ implode('. ', $f['reasons']) }}</small>@endif
        @elseif ($f && $f['failed'])
            <span class="pgd-plain">Health could not be worked out just now.</span>
        @else
            <span class="pgd-plain">Not rated: the project is {{ $project->archived_at ? 'archived' : ($finished ? 'completed' : 'not among the first '.\App\Http\Controllers\DashboardController::RATED_ROWS.' open projects') }}.</span>
        @endif
    </dd></div>
    <div><dt>Due</dt><dd>
        @if ($project->due_date){{ $project->due_date->format('j M Y') }}
            @if (! $finished && $left !== null)<small class="{{ $left < 0 ? 'pgd-late' : '' }}">{{ $left < 0 ? abs($left).' '.Str::plural('day', abs($left)).' past due' : ($left === 0 ? 'Due today' : 'In '.$left.' '.Str::plural('day', $left)) }}</small>@endif
        @else<span class="pgd-plain">No due date</span>@endif
    </dd></div>
    @if ($rated)
        <div><dt>Finishes (forecast)</dt><dd>
            @if ($f['forecast']){{ \Illuminate\Support\Carbon::parse($f['forecast'])->format('j M Y') }}<small class="{{ $f['late'] ? 'pgd-late' : '' }}">{{ $f['late'] ? $f['late'].' '.Str::plural('day', $f['late']).' after the due date. ' : 'On or before the due date. ' }}A forecast from task dates.</small>
            @else<span class="pgd-plain">None: no dated tasks</span>@endif
        </dd></div>
    @endif
    <div><dt>Tasks done</dt><dd>{{ $project->tasks_count ? $project->done_tasks_count.' of '.$project->tasks_count : 'No tasks' }}</dd></div>
    <div><dt>Overdue tasks</dt><dd class="{{ $project->overdue_tasks_count ? 'pgd-late' : '' }}">{{ $project->overdue_tasks_count }}</dd></div>
    @if ($rated)
        <div><dt>Serious risks</dt><dd>{{ $f['risks'] }}</dd></div>
        <div><dt>Critical issues</dt><dd>{{ $f['critical'] }}</dd></div>
    @endif
</dl>
<div class="pgd-line">@include('dashboard._line', ['project' => $project, 'today' => $today])</div>
<div class="pgd-btns">
    <a class="pg-btn pg-btn--outline" href="{{ route('projects.show', $project) }}">Open project</a>
    <a class="pg-btn pg-btn--ghost" href="{{ route('projects.board', $project) }}">Board</a>
    <a class="pg-btn pg-btn--ghost" href="{{ route('projects.schedule', $project) }}">Schedule</a>
    <a class="pg-btn pg-btn--ghost" href="{{ route('projects.risks', $project) }}">Risks</a>
</div>
