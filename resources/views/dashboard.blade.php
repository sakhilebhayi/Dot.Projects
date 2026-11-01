<x-app-layout>
    <x-slot name="title">Projects</x-slot>
    <x-slot name="header"><b>Projects</b></x-slot>
    @php
        $open = $projects->whereNotIn('status', ['completed']);
        $late = $open->filter(fn ($p) => $p->overdue_tasks_count > 0 || ($p->due_date && $p->due_date->lt($today)));
        $canCreate = auth()->user()->can('create', App\Models\Project::class);
        $pill = fn ($s) => match ($s) { 'active' => '', 'pending_closure_approval' => 'pg-pill--warning', 'completed' => 'pg-pill--positive', default => '' };
    @endphp
    <div class="pg-page">
        <p class="pg-crumb">{{ auth()->user()->currentTeam?->name }}</p>
        <div class="pg-titlerow">
            <div>
                <h1 class="pg-title">Projects</h1>
                <p class="pg-finding">
                    @if ($projects->isEmpty() && $search === '' && $statusFilter === '')
                        No projects yet.@if ($canCreate) Start one; you can begin with just a name and add detail as you go.@endif
                    @elseif ($open->isEmpty())
                        No open projects{{ $search !== '' || $statusFilter !== '' ? ' match this filter' : '' }}.
                    @elseif ($late->isEmpty())
                        <b>{{ $open->count() }} open {{ Str::plural('project', $open->count()) }}</b>, none with overdue work.
                    @else
                        <b>{{ $late->count() }} of {{ $open->count() }} open {{ Str::plural('project', $open->count()) }}</b> {{ $late->count() === 1 ? 'has' : 'have' }} overdue work or {{ $late->count() === 1 ? 'is' : 'are' }} past {{ $late->count() === 1 ? 'its' : 'their' }} due date.
                    @endif
                </p>
            </div>
            @if ($canCreate)
                <div class="pg-actions"><a href="{{ route('projects.create') }}" class="pg-btn pg-btn--primary"><span class="material-symbols-rounded" aria-hidden="true" style="font-size:18px">add</span>New project</a></div>
            @endif
        </div>

        <form method="GET" action="{{ route('dashboard') }}" class="pg-filters" role="search">
            <label for="dash-search" class="pg-sr">Search projects by name</label>
            <input id="dash-search" type="search" name="search" value="{{ $search }}" placeholder="Search projects by name" class="pg-input">
            <label for="dash-status" class="pg-sr">Status</label>
            <select id="dash-status" name="status" class="pg-select">
                <option value="">Any status</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected($statusFilter === $value)>{{ $label }}</option>
                @endforeach
                <option value="archived" @selected($statusFilter === 'archived')>Archived</option>
            </select>
            <button type="submit" class="pg-btn pg-btn--secondary">Filter</button>
            @if ($search !== '' || $statusFilter !== '')
                <a href="{{ route('dashboard') }}" class="pg-btn pg-btn--ghost">Clear</a>
            @elseif ($archivedCount)
                <a href="{{ route('dashboard', ['status' => 'archived']) }}" class="pg-btn pg-btn--ghost">{{ $archivedCount }} archived</a>
            @endif
        </form>

        @if ($projects->isEmpty())
            <div class="pg-card"><p class="pg-empty">{{ $search !== '' || $statusFilter !== '' ? 'No project matches. Try another name or status.' : 'Projects you create appear here with their next milestone, progress and anything overdue.' }}</p></div>
        @else
            <div class="pg-table-wrap">
                <table class="pg-table">
                    <thead>
                        <tr>
                            <th scope="col">Project</th>
                            <th scope="col">Status</th>
                            <th scope="col">Next milestone</th>
                            <th scope="col">Tasks done</th>
                            <th scope="col" class="r">Overdue</th>
                            <th scope="col" class="r">Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($projects as $project)
                            @php
                                $next = $project->milestones->first();
                                $pct = $project->tasks_count ? round($project->done_tasks_count / $project->tasks_count * 100) : 0;
                                $pastDue = $project->status !== 'completed' && $project->due_date && $project->due_date->lt($today);
                            @endphp
                            <tr>
                                <td class="pg-name"><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a>@if ($project->description)<span class="pg-sub">{{ $project->description }}</span>@endif</td>
                                <td data-label="Status"><span class="pg-pill {{ $pill($project->status) }}">{{ $project->archived_at ? 'Archived' : ($statuses[$project->status] ?? Str::headline($project->status)) }}</span></td>
                                <td data-label="Next milestone">@if ($next){{ $next->title }}<span class="pg-sub {{ $next->due_date->lt($today) ? 'pg-neg' : '' }}">{{ $next->due_date->lt($today) ? 'Was due '.$next->due_date->format('j M') : $next->due_date->format('j M Y') }}</span>@else<span class="pg-muted">None dated</span>@endif</td>
                                <td data-label="Tasks done">
                                    @if ($project->tasks_count)
                                        <div style="display:grid;grid-template-columns:minmax(60px,120px) auto;gap:10px;align-items:center">
                                            <span class="pg-progress" role="img" aria-label="{{ $project->done_tasks_count }} of {{ $project->tasks_count }} tasks done"><i style="width:{{ $pct }}%"></i></span>
                                            <span>{{ $project->done_tasks_count }} of {{ $project->tasks_count }}</span>
                                        </div>
                                    @else
                                        <span class="pg-muted">No tasks</span>
                                    @endif
                                </td>
                                <td data-label="Overdue" class="r {{ $project->overdue_tasks_count ? 'pg-neg' : 'pg-muted' }}">{{ $project->overdue_tasks_count }}</td>
                                <td data-label="Due" class="r {{ $pastDue ? 'pg-neg' : '' }}">{{ $project->due_date?->format('j M Y') ?? '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
