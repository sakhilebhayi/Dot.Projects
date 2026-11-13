<x-app-layout>
    <x-slot name="title">Projects</x-slot>
    <x-slot name="header"><b>Projects</b></x-slot>
    @php
        $filtered = $search !== '' || $statusFilter !== '';
        $company = auth()->user()->currentTeam?->name;
        $pill = fn ($s) => match ($s) { 'active' => '', 'pending_closure_approval' => 'pg-pill--warning', 'completed' => 'pg-pill--positive', default => '' };
        // The featured project's date line: from its start (or first milestone) to its due date (or last milestone).
        if ($featured) {
            $dated = $featured->milestones;
            $from = collect([$featured->start_date, $dated->min('due_date'), $today])->filter()->min()->copy()->startOfMonth();
            $to = collect([$featured->due_date, $dated->max('due_date'), $today])->filter()->max()->copy()->endOfMonth();
            $span = max(1, $from->diffInDays($to));
            $at = fn ($d) => round($from->diffInDays($d) / $span * 100, 3).'%';
            $months = []; for ($m = $from->copy(); $m->lte($to); $m->addMonth()) { $months[] = $m->copy(); }
            $step = (int) ceil(count($months) / 8); // at most eight month labels, so a long project stays readable
            $days = $featured->due_date ? (int) $today->diffInDays($featured->due_date, false) : null;
            $prev = null;
        }
    @endphp
    <div class="pg-page">
        {{-- R-50: the arrival. The original logo, a welcome from real data, one primary action, a real date line. --}}
        <section class="pg-arrive" aria-labelledby="arrive-h">
            {{-- The way back, top right on every platform's landing: InfoDot home and its Platforms page. --}}
            <nav class="pg-arrive-back" aria-label="Dot ecosystem">
                <a href="{{ $infodot }}/home">InfoDot</a>
                <a href="{{ $infodot }}/platforms">Platforms</a>
            </nav>
            <div class="pg-arrive-say">
                <div class="pg-arrive-brand">
                    <img class="day" src="{{ asset('images/logo.png') }}" alt="Dot.Projects" width="176" height="132">
                    <img class="night" src="{{ asset('images/logo-light.png') }}" alt="Dot.Projects" width="176" height="132">
                </div>
                <h1 id="arrive-h" class="pg-arrive-h">Know when every project lands.</h1>
                <p class="pg-arrive-lede"><b>Welcome{{ $firstName !== '' ? ', '.$firstName : '' }}.</b>
                    @if ($company)
                        This is where {{ $company }} plans and runs its projects: work, dates, people, money and risks in one programme, so you always know what moves the finish date.
                    @else
                        This is where projects are planned and run: work, dates, people, money and risks in one programme, so you always know what moves the finish date.
                    @endif
                </p>
                <p class="pg-finding">
                    @if (! $hasAnyProject)
                        No projects yet.@if ($canCreate) Start one; you can begin with just a name and add detail as you go.@else Projects appear here once someone in the business starts one you can see.@endif
                    @elseif ($openCount === 0)
                        No open projects. Everything here is completed or archived.
                    @elseif ($lateCount === 0)
                        <b>{{ $openCount }} open {{ Str::plural('project', $openCount) }}</b>, none with overdue work.
                    @else
                        <b>{{ $lateCount }} of {{ $openCount }} open {{ Str::plural('project', $openCount) }}</b> {{ $lateCount === 1 ? 'has' : 'have' }} overdue work or {{ $lateCount === 1 ? 'is' : 'are' }} past {{ $lateCount === 1 ? 'its' : 'their' }} due date.
                    @endif
                </p>
                <div class="pg-arrive-act">
                    <a href="{{ route($primary['route']) }}" class="pg-btn pg-btn--primary pg-btn--lead" data-primary-action><span class="material-symbols-rounded" aria-hidden="true">{{ $primary['icon'] }}</span>{{ $primary['label'] }}</a>
                    @if ($canCreate)
                        <a href="{{ route('templates.index') }}" class="pg-arrive-also">Start from a template</a>
                    @endif
                </div>
            </div>

            <div class="pg-arrive-show">
                <section class="pg-card" aria-labelledby="arrive-land-h">
                    @if ($featured)
                        <div class="pg-card-head"><h2 id="arrive-land-h">When {{ $featured->name }} lands</h2><a href="{{ route('projects.show', $featured) }}">Open the project</a></div>
                        <div class="pg-land-line" role="img" aria-label="Milestones of {{ $featured->name }} on a date line from {{ $from->format('j M Y') }} to {{ $to->format('j M Y') }}">
                            @foreach ($months as $i => $m)<span class="tick" style="left:{{ $at($m) }}">@if ($i % $step === 0)<span>{{ $m->format($m->month === 1 ? 'M Y' : 'M') }}</span>@endif</span>@endforeach
                            <span class="axis"></span>
                            <span class="today" style="left:{{ $at($today) }}"><span>Today</span></span>
                            @foreach ($dated as $i => $m)
                                @php
                                    $late = $m->status !== 'completed' && $m->due_date->lt($today);
                                    $low = $prev && $prev->diffInDays($m->due_date) < 21;
                                    $prev = $low ? null : $m->due_date;
                                @endphp
                                <span class="ms {{ $m->status === 'completed' ? 'done' : ($late ? 'late' : '') }}" style="left:{{ $at($m->due_date) }}" title="{{ $m->title }}, {{ $m->due_date->format('j M Y') }}"></span>
                                <span class="lbl {{ $low ? 'low' : '' }} {{ $i % 2 ? 'odd' : '' }}" style="left:{{ $at($m->due_date) }}"><span class="t">{{ Str::limit($m->title, 28) }}</span><small class="{{ $late ? 'late' : '' }}">{{ $m->due_date->format('j M') }}</small></span>
                            @endforeach
                        </div>
                        <p class="pg-arrive-note">
                            Milestones on their planned dates; filled dots are done, red ones are past due.
                            @if ($featured->due_date)
                                Due {{ $featured->due_date->format('j M Y') }}, @if ($days < 0)<span class="pg-neg">{{ abs($days) }} {{ Str::plural('day', abs($days)) }} past due</span>.@elseif ($days === 0){{ 'today.' }}@else{{ 'in '.$days.' '.Str::plural('day', $days).'.' }}@endif
                            @else
                                No due date set.
                            @endif
                            @if ($featured->tasks_count) {{ $featured->done_tasks_count }} of {{ $featured->tasks_count }} {{ Str::plural('task', $featured->tasks_count) }} done.@endif
                        </p>
                    @else
                        <div class="pg-card-head"><h2 id="arrive-land-h">When you land</h2></div>
                        <div class="pg-land-line pg-land-line--bare" aria-hidden="true"><span class="axis"></span><span class="today" style="left:50%"><span>Today, {{ $today->format('j M') }}</span></span></div>
                        <p class="pg-empty">
                            @if ($hasAnyProject)
                                No open project has a dated milestone yet. Give a milestone a due date on a project's board and its date line is drawn here.
                            @elseif ($canCreate)
                                Nothing to draw yet. Start a project, add milestones with dates, and this line shows when it lands and what is late.
                            @else
                                Nothing to draw yet. Once the business has a project you can see, its milestones appear on this line.
                            @endif
                        </p>
                    @endif
                </section>

                @if ($hasAnyProject)
                    <section class="pg-card" aria-labelledby="arrive-mine-h">
                        <div class="pg-card-head"><h2 id="arrive-mine-h">Given to you</h2>@if ($mineCount)<small>{{ $mineCount }} open {{ Str::plural('task', $mineCount) }}{{ $mineCount > $mine->count() ? ', the '.$mine->count().' soonest shown' : '' }}</small>@endif</div>
                        @forelse ($mine as $task)
                            <div class="pg-row">
                                <span><a href="{{ route('projects.tasks.show', [$task->project, $task]) }}">{{ $task->title }}</a> <span class="pg-sub">in {{ $task->project->name }}</span></span>
                                @if ($task->due_date)
                                    <span class="{{ $task->due_date->lt($today) ? 'pg-neg' : 'pg-muted' }}">{{ $task->due_date->lt($today) ? 'Was due '.$task->due_date->format('j M') : 'Due '.$task->due_date->format('j M') }}</span>
                                @else
                                    <span class="pg-muted">No date</span>
                                @endif
                            </div>
                        @empty
                            <p class="pg-empty">No open task is assigned to you. Work given to you on any project shows here, soonest first.</p>
                        @endforelse
                    </section>
                @endif
            </div>
        </section>

        <div class="pg-arrive-list" id="projects">
            <h2 class="pg-arrive-list-h">Projects</h2>
        </div>

        <form method="GET" action="{{ route('dashboard') }}#projects" class="pg-filters" role="search">
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
            @if ($filtered)
                <a href="{{ route('dashboard') }}#projects" class="pg-btn pg-btn--ghost">Clear</a>
            @elseif ($archivedCount)
                <a href="{{ route('dashboard', ['status' => 'archived']) }}#projects" class="pg-btn pg-btn--ghost">{{ $archivedCount }} archived</a>
            @endif
        </form>

        @if ($projects->isEmpty())
            <div class="pg-card"><p class="pg-empty">{{ $filtered ? 'No project matches. Try another name or status.' : 'Projects you create appear here with their next milestone, progress and anything overdue.' }}</p></div>
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
                                <td class="pg-name"><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a>@if ($project->isPrivate()) <span class="pg-pill" style="margin-left:4px">Private</span>@endif @if ($project->description)<span class="pg-sub">{{ $project->description }}</span>@endif</td>
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
