<x-app-layout>
    <x-slot name="title">Projects</x-slot>
    <x-slot name="header"><b>Projects</b></x-slot>
    @php
        $filtered = $search !== '' || $statusFilter !== '';
        $company = auth()->user()->currentTeam?->name;
        $hl = ['off_track' => ['Off track', 'pg-pill--negative'], 'at_risk' => ['At risk', 'pg-pill--warning'], 'on_track' => ['On track', 'pgd-h-on']];
        $openRows = $projects->filter(fn ($p) => $p->archived_at === null && $p->status !== 'completed');
        $doneRows = $projects->filter(fn ($p) => $p->archived_at === null && $p->status === 'completed');

        // The horizon: the next dated milestone of every open project on one line, today marked. Real dates only.
        $horizon = [];
        foreach ($openRows as $p) {
            if ($n = $p->milestones->first(fn ($m) => $m->status !== 'completed')) { $horizon[] = ['name' => $p->name, 'on' => $n->due_date, 'late' => $n->due_date->lt($today)]; }
        }
        $hFrom = collect([$today, ...array_column($horizon, 'on')])->min()->copy()->startOfMonth();
        $hTo = collect([$today, ...array_column($horizon, 'on')])->max()->copy()->endOfMonth();
        if ($hFrom->diffInDays($hTo) < 60) { $hTo = $hFrom->copy()->addMonths(2)->endOfMonth(); }
        $hSpan = max(1, $hFrom->diffInDays($hTo));
        $hAt = fn ($d) => round($hFrom->diffInDays($d) / $hSpan * 100, 3);
    @endphp
    <div class="pg-page pgd" data-pgd style="padding:0;max-width:none">
        <a class="pg-sr pgd-skip" href="#projects">Skip to the projects list</a>

        {{-- The arrival: the original logo, a sentence counted from records, the one lead action, and the horizon. --}}
        <section class="pgd-arrive" aria-labelledby="arrive-h">
            <a class="pgd-logo" href="{{ route('dashboard') }}" aria-label="Dot.Projects">
                <img class="day" src="{{ asset('images/logo.png') }}" alt="Dot.Projects" width="96" height="72">
                <img class="night" src="{{ asset('images/logo-light.png') }}" alt="Dot.Projects" width="96" height="72">
            </a>
            <div class="pgd-say">
                @if (! $hasAnyProject)
                    <h1 id="arrive-h">Know when every project lands.</h1>
                    <p><b>Welcome{{ $firstName !== '' ? ', '.$firstName : '' }}.</b>
                        {{ $company ? 'This is where '.$company.' plans and runs its projects' : 'This is where projects are planned and run' }}: work, dates, people, money and risks in one programme. No projects yet.@if ($canCreate) Start one; you can begin with just a name and add detail as you go.@else Projects appear here once someone in the business starts one you can see.@endif
                    </p>
                @else
                    <h1 id="arrive-h">
                        @if ($openCount === 0)
                            No open projects. Everything here is completed or archived.
                        @elseif ($lateCount === 0)
                            <b>{{ $openCount }} open {{ Str::plural('project', $openCount) }}</b>, none with overdue work.
                        @else
                            <b>{{ $lateCount }} of {{ $openCount }} open {{ Str::plural('project', $openCount) }}</b> {{ $lateCount === 1 ? 'has' : 'have' }} overdue work or {{ $lateCount === 1 ? 'is' : 'are' }} past {{ $lateCount === 1 ? 'its' : 'their' }} due date.
                        @endif
                    </h1>
                    <p><b>Welcome{{ $firstName !== '' ? ', '.$firstName : '' }}.</b> {{ $company ? $company.', as of today, ' : 'As of today, ' }}{{ $today->format('j M') }}.</p>
                @endif
            </div>
            <div class="pgd-act">
                <a href="{{ route($primary['route']) }}" class="pg-btn pg-btn--primary pg-btn--lead" data-primary-action><span class="material-symbols-rounded" aria-hidden="true">{{ $primary['icon'] }}</span>{{ $primary['label'] }}</a>
                @if ($canCreate)
                    <a href="{{ route('templates.index') }}" class="pgd-also">Start from a template</a>
                @endif
            </div>
            @if ($horizon !== [] || ! $hasAnyProject)
                <div class="pgd-horizon" @if ($horizon !== []) role="img" aria-label="The next milestone of each open project on a date line from {{ $hFrom->format('j M Y') }} to {{ $hTo->format('j M Y') }}" @else aria-hidden="true" @endif>
                    <span class="axis"></span>
                    @foreach ([$hFrom->copy(), $hFrom->copy()->addMonths(1), $hFrom->copy()->addMonths(2)] as $m)
                        @if ($m->lte($hTo))<span class="tick" style="left:{{ $hAt($m) }}%"></span>@endif
                    @endforeach
                    <span class="today" style="left:{{ $horizon === [] ? 50 : $hAt($today) }}%"><span>Today, {{ $today->format('j M') }}</span></span>
                    @foreach ($horizon as $i => $h)
                        <span class="dot {{ $h['late'] ? 'late' : '' }}" style="left:{{ $hAt($h['on']) }}%;--i:{{ $i }}" title="{{ $h['name'] }}, {{ $h['on']->format('j M Y') }}"></span>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($hasAnyProject)
            <section class="pgd-desk {{ $memberFirst ? 'pgd-desk--member' : '' }}" id="projects" aria-label="Projects">
                <div class="pgd-ledger">
                    <form method="GET" action="{{ route('dashboard') }}#projects" class="pgd-tools" role="search">
                        <x-dot.filter-search id="dash-search" name="search" :value="$search" placeholder="Search projects by name" label="Search projects by name" class="pg-filter-search" />
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
                        @endif
                        <span class="pgd-count" role="status">{{ $projects->count() }} {{ Str::plural('project', $projects->count()) }}</span>
                    </form>

                    @if ($projects->isEmpty())
                        <p class="pgd-none">{{ $filtered ? 'No project matches. Try another name or status.' : 'Projects you create appear here with their next milestone, progress and anything overdue.' }}</p>
                    @else
                        <table class="pgd-table" id="ledger">
                            <thead><tr>
                                <th scope="col" aria-sort="descending" data-key="attention"><button class="pgd-sort" type="button">Project</button></th>
                                <th scope="col" class="pgd-col-status" data-key="status"><button class="pgd-sort" type="button">Status</button></th>
                                <th scope="col" class="pgd-col-health" data-key="health"><button class="pgd-sort" type="button">Health</button></th>
                                <th scope="col" class="pgd-col-ms" data-key="ms"><button class="pgd-sort" type="button">Next milestone</button></th>
                                <th scope="col" class="pgd-col-done" data-key="done"><button class="pgd-sort" type="button">Tasks done</button></th>
                                <th scope="col" class="r pgd-col-over" data-key="over"><button class="pgd-sort" type="button">Overdue</button></th>
                                <th scope="col" class="r pgd-col-due" data-key="due"><button class="pgd-sort" type="button">Due</button></th>
                            </tr></thead>
                            <tbody>
                                @foreach ($projects as $i => $project)
                                    @php
                                        $f = $ratings[$project->id] ?? null;
                                        $rated = $f && $f['health'] !== null;
                                        $next = $project->milestones->first(fn ($m) => $m->status !== 'completed');
                                        $pct = $project->tasks_count ? round($project->done_tasks_count / $project->tasks_count * 100) : 0;
                                        $pastDue = $project->status !== 'completed' && $project->due_date && $project->due_date->lt($today);
                                        $hasPanel = in_array($project->id, $panelIds, true);
                                    @endphp
                                    <tr data-id="{{ $project->id }}" @if ($hasPanel) data-panel @endif class="{{ $project->id === $selectedId ? 'is-selected' : '' }}" style="--i:{{ min($i, 12) }}"
                                        data-attention="{{ $i }}" data-health="{{ $rated ? ['off_track' => 0, 'at_risk' => 1, 'on_track' => 2][$f['health']] : 3 }}"
                                        data-status="{{ $project->archived_at ? 'Archived' : ($statuses[$project->status] ?? Str::headline($project->status)) }}"
                                        data-ms="{{ $next ? $next->due_date->timestamp : '' }}" data-done="{{ $project->tasks_count ? $project->done_tasks_count / $project->tasks_count : -1 }}"
                                        data-over="{{ $project->overdue_tasks_count }}" data-due="{{ $project->due_date?->timestamp ?? '' }}">
                                        <td class="pgd-name"><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a>@if ($project->isPrivate()) <span class="pg-pill">Private</span>@endif @if ($project->description)<span class="pgd-sub">{{ $project->description }}</span>@endif</td>
                                        <td class="pgd-col-status pgd-plain">{{ $project->archived_at ? 'Archived' : ($statuses[$project->status] ?? Str::headline($project->status)) }}</td>
                                        <td class="pgd-col-health" data-label="Health">
                                            @if ($rated)<span class="pg-pill {{ $hl[$f['health']][1] }}">{{ $hl[$f['health']][0] }}</span>
                                            @elseif ($project->archived_at)<span class="pgd-plain">Archived</span>
                                            @elseif ($project->status === 'completed')<span class="pgd-plain">Done</span>
                                            @else<span class="pgd-plain">{{ $f && $f['failed'] ? 'Not worked out' : '–' }}</span>@endif
                                        </td>
                                        <td class="pgd-col-ms" data-label="Next milestone">@if ($next){{ $next->title }}<span class="pgd-sub {{ $next->due_date->lt($today) ? 'pgd-late' : '' }}">{{ $next->due_date->lt($today) ? 'Was due '.$next->due_date->format('j M') : $next->due_date->format('j M Y') }}</span>@else<span class="pgd-plain">None dated</span>@endif</td>
                                        <td class="pgd-col-done" data-label="Tasks done">
                                            @if ($project->tasks_count)
                                                <span class="pgd-done"><span class="pg-progress" role="img" aria-label="{{ $project->done_tasks_count }} of {{ $project->tasks_count }} tasks done"><i style="--p:{{ $pct / 100 }}"></i></span><span>{{ $project->done_tasks_count }} of {{ $project->tasks_count }}</span></span>
                                            @else<span class="pgd-plain">No tasks</span>@endif
                                        </td>
                                        <td class="r pgd-col-over {{ $project->overdue_tasks_count ? 'pgd-late' : 'pgd-plain' }}" data-label="Overdue">{{ $project->overdue_tasks_count }}</td>
                                        <td class="r pgd-col-due {{ $pastDue ? 'pgd-late' : '' }}" data-label="Due">{{ $project->due_date?->format('j M Y') ?? '–' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                    <p class="pgd-foot">
                            @if ($projects->isNotEmpty())<span><b>{{ $openRows->count() }}</b> open</span>
                            <span><b>{{ $doneRows->count() }}</b> completed</span>@endif
                            @if ($statusFilter === 'archived')@if ($projects->isNotEmpty())<span><b>{{ $projects->count() }}</b> archived</span>@endif
                            @elseif ($archivedCount)<a href="{{ route('dashboard', ['status' => 'archived']) }}#projects">{{ $archivedCount }} archived</a>@endif
                        </p>
                </div>

                <aside class="pgd-side" aria-label="Selected project and your work">
                    <div class="pgd-sel" id="sel" aria-live="polite">
                        @foreach ($projects->filter(fn ($p) => in_array($p->id, $panelIds, true)) as $project)
                            <div class="pgd-panel" data-for="{{ $project->id }}" @if ($project->id !== $selectedId) hidden @endif>
                                @include('dashboard._panel', ['project' => $project, 'today' => $today])
                            </div>
                        @endforeach
                    </div>
                    <section class="pgd-mine" aria-labelledby="mine-h">
                        <h2 id="mine-h">Given to you @if ($mineCount)<small>{{ $mineCount }} open {{ Str::plural('task', $mineCount) }}{{ $mineCount > $mine->count() ? ', the '.$mine->count().' soonest shown' : '' }}</small>@endif</h2>
                        @if ($mine->isNotEmpty())
                            <ul class="pgd-mine-list">
                                @foreach ($mine as $task)
                                <li>
                                    <span class="pgd-mine-t"><a href="{{ route('projects.tasks.show', [$task->project, $task]) }}">{{ $task->title }}</a><span class="pgd-in">in {{ $task->project->name }}</span></span>
                                    @if ($task->due_date)
                                        <span class="{{ $task->due_date->lt($today) ? 'pgd-late' : '' }}">{{ $task->due_date->lt($today) ? 'Was due '.$task->due_date->format('j M') : 'Due '.$task->due_date->format('j M') }}</span>
                                    @else
                                        <span>No date</span>
                                    @endif
                                </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="pgd-empty">No open task is assigned to you. Work given to you on any project shows here, soonest first.</p>
                        @endif
                    </section>
                </aside>
            </section>
        @endif
    </div>
</x-app-layout>
