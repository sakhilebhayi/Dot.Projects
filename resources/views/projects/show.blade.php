<x-app-layout>
    <x-slot name="title">{{ $project->name }}</x-slot>
    <x-slot name="header"><a href="{{ route('dashboard') }}" style="text-decoration:none">Projects</a> <span aria-hidden="true">/</span> <b>{{ $project->name }}</b></x-slot>
    @php
        $counts = $summary->counts();
        $total = array_sum($counts);
        $rows = $summary->milestoneRows();
        $dated = $rows->filter(fn ($r) => $r['milestone']->due_date);
        // The date line runs from the project's start (or first milestone) to its due date (or last milestone).
        $baseDates = collect($base['milestone'] ?? [])->map(fn ($i) => $i->due_date)->filter();
        $from = collect([$project->start_date, $dated->min(fn ($r) => $r['milestone']->due_date), $baseDates->min(), $summary->today])->filter()->min()->copy()->startOfMonth();
        $to = collect([$project->due_date, $dated->max(fn ($r) => $r['milestone']->due_date), $baseDates->max(), $summary->today])->filter()->max()->copy()->endOfMonth();
        $forecast = $schedule->forecastFinish();
        $baseDue = isset($base['project'][$project->id]) ? $base['project'][$project->id]->due_date : null;
        $span = max(1, $from->diffInDays($to));
        $at = fn ($d) => round($from->diffInDays($d) / $span * 100, 3).'%';
        $days = $summary->daysToDue();
        $colours = ['backlog' => 'var(--pg-raised)', 'todo' => 'var(--pg-control)', 'in_progress' => 'var(--pg-bar-active)', 'review' => 'var(--pg-muted)', 'done' => 'var(--pg-bar-done)'];
        $months = []; for ($m = $from->copy(); $m->lte($to); $m->addMonth()) { $months[] = $m->copy(); }
        $prev = null;
    @endphp
    <div class="pg-page">
        @include('projects._head', ['view' => 'overview'])

        @if ($project->status === 'pending_closure_approval')
            <div class="pg-notice" role="status"><span><b>Every milestone is complete.</b> {{ $project->owner?->name ?? 'The owner' }} confirms or rejects closing the project on the board.</span><a href="{{ route('projects.board', $project) }}" class="pg-btn pg-btn--secondary pg-btn--sm">Go to the board</a></div>
        @endif

        <div class="pg-grid">
            <section class="pg-card pg-s12" aria-labelledby="land-h">
                <div class="pg-card-head"><h2 id="land-h">When we land</h2><small>Milestones on their planned dates. Black dots are done; red ones are past due.@if ($baseline) Dashed rings are baseline {{ $baseline->number }}.@endif</small></div>
                <div class="pg-land">
                    @if ($dated->isEmpty())
                        <p class="pg-empty" style="align-self:center">No milestone has a date yet. @can('update', $project)<a href="{{ route('projects.board', $project) }}#add-milestone">Add a dated milestone</a> to draw this line.@endcan</p>
                    @else
                        <div class="pg-land-line" role="img" aria-label="Milestones on a date line from {{ $from->format('j M Y') }} to {{ $to->format('j M Y') }}">
                            @foreach ($months as $m)<span class="tick" style="left:{{ $at($m) }}"><span>{{ $m->format($m->month === 1 ? 'M Y' : 'M') }}</span></span>@endforeach
                            <span class="axis"></span>
                            <span class="today" style="left:{{ $at($summary->today) }}"><span>Today</span></span>
                            @foreach ($dated->sortBy(fn ($r) => $r['milestone']->due_date->timestamp)->values() as $i => $r)
                                @php
                                    $m = $r['milestone'];
                                    $low = $prev && $prev->diffInDays($m->due_date) < 21;
                                    $prev = $low ? null : $m->due_date;
                                @endphp
                                @php $mb = isset($base['milestone'][$m->id]) ? $base['milestone'][$m->id]->due_date : null; @endphp
                                @if ($mb && ! $mb->isSameDay($m->due_date))<span class="ms base" style="left:{{ $at($mb) }}" title="{{ $m->title }}, baseline {{ $baseline->number }}: {{ $mb->format('j M Y') }}"></span>@endif
                                <span class="ms {{ $m->status === 'completed' ? 'done' : ($r['late'] ? 'late' : '') }}" style="left:{{ $at($m->due_date) }}" title="{{ $m->title }}, {{ $m->due_date->format('j M Y') }}"></span>
                                <span class="lbl {{ $low ? 'low' : '' }} {{ $i % 2 ? 'odd' : '' }}" style="left:{{ $at($m->due_date) }}"><span class="t">{{ Str::limit($m->title, 28) }}</span><small class="{{ $r['late'] ? 'late' : '' }}">{{ $m->due_date->format('j M') }}</small></span>
                            @endforeach
                        </div>
                    @endif
                    <div class="pg-land-big">
                        <span class="k">Project due</span>
                        @if ($project->due_date)
                            <span class="v">{{ $project->due_date->format('j M') }}</span>
                            <span class="d">{{ $project->due_date->format('Y') }}, @if ($project->status === 'completed')completed @elseif ($days < 0)<span class="pg-neg">{{ abs($days) }} {{ Str::plural('day', abs($days)) }} past due</span>@elseif ($days === 0)today @else in {{ $days }} {{ Str::plural('day', $days) }}@endif</span>
                        @else
                            <span class="v">–</span>
                            <span class="d pg-muted">No due date set</span>
                        @endif
                        <span class="d pg-muted">{{ $summary->done() }} of {{ $total }} {{ Str::plural('task', $total) }} done</span>
                        @if ($forecast)
                            <span class="d">Tasks as dated finish <a href="{{ route('projects.schedule', $project) }}">{{ $forecast->format('j M') }}</a>@if ($project->due_date && $forecast->gt($project->due_date)), <span class="pg-neg">{{ (int) $project->due_date->diffInDays($forecast) }} {{ Str::plural('day', (int) $project->due_date->diffInDays($forecast)) }} late</span>@endif</span>
                        @endif
                        @if ($baseline && $baseDue)
                            <span class="d pg-muted">Baseline {{ $baseline->number }} was due {{ $baseDue->format('j M') }}@if ($project->due_date && ! $baseDue->isSameDay($project->due_date))@php $moved = (int) $baseDue->diffInDays($project->due_date, false); @endphp, now {{ abs($moved) }} {{ Str::plural('day', abs($moved)) }} {{ $moved > 0 ? 'later' : 'earlier' }}@endif</span>
                        @endif
                    </div>
                </div>
            </section>

            <section class="pg-card pg-s5" aria-labelledby="way-h">
                <div class="pg-card-head"><h2 id="way-h">In the way</h2><small>{{ $summary->overdue()->count() }} overdue, {{ $summary->blocked()->count() }} blocked</small></div>
                @forelse ($summary->inTheWay() as $task)
                    @php $late = $task->due_date && $task->due_date->lt($summary->today); @endphp
                    <div class="pg-row">
                        <div>
                            @if ($task->assignee)<span class="pg-avatar" style="margin-right:6px;vertical-align:-6px" aria-hidden="true">{{ App\Services\ProjectSummary::initials($task->assignee->name) }}</span>@endif{{ $task->title }}
                            <div class="pg-sub">{{ $task->assignee?->name ?? 'Unassigned' }}@if ($task->milestone), {{ $task->milestone->title }}@endif @if ($task->isBlocked()), waits for {{ $task->predecessors->where('status', '!=', 'done')->pluck('title')->map(fn ($t) => Str::limit($t, 30))->join(', ') }}@endif</div>
                        </div>
                        @if ($task->isBlocked() && ! $late)
                            <span class="pg-pill pg-pill--warning" title="Waits for {{ $task->predecessors->where('status', '!=', 'done')->pluck('title')->join(', ') }}">Blocked</span>
                        @elseif ($late)
                            <span class="pg-pill pg-pill--negative">{{ (int) $task->due_date->diffInDays($summary->today) }} {{ Str::plural('day', (int) $task->due_date->diffInDays($summary->today)) }} late</span>
                        @else
                            <span class="pg-pill pg-pill--warning">Urgent</span>
                        @endif
                    </div>
                @empty
                    <p class="pg-empty">Nothing is overdue, blocked or marked urgent.</p>
                @endforelse
            </section>

            <section class="pg-card pg-s4" aria-labelledby="ms-h">
                <div class="pg-card-head"><h2 id="ms-h">Milestones</h2><small>{{ $rows->where('milestone.status', 'completed')->count() }} of {{ $rows->count() }} complete</small></div>
                @forelse ($rows as $r)
                    @php $m = $r['milestone']; @endphp
                    <div class="pg-row">
                        <div>{{ $m->title }}<div class="pg-sub {{ $r['late'] ? 'pg-neg' : '' }}">{{ $m->due_date ? ($r['late'] ? 'Was due ' : 'Due ').$m->due_date->format('j M Y') : 'No date' }}, {{ $r['done'] }} of {{ $r['total'] }} {{ Str::plural('task', $r['total']) }} done</div></div>
                        <span class="pg-pill {{ $m->status === 'completed' ? 'pg-pill--positive' : ($r['late'] ? 'pg-pill--negative' : '') }}">{{ $m->status === 'completed' ? 'Complete' : ($r['late'] ? 'Late' : ($m->status === 'in_progress' ? 'Under way' : 'Not started')) }}</span>
                    </div>
                @empty
                    <p class="pg-empty">No milestones yet.</p>
                @endforelse
            </section>

            <section class="pg-card pg-s3" aria-labelledby="work-h">
                <div class="pg-card-head"><h2 id="work-h">Work</h2><small>{{ $total }} {{ Str::plural('task', $total) }}</small></div>
                @if ($total)
                    <div class="pg-stack" role="img" aria-label="{{ collect($counts)->map(fn ($n, $s) => $n.' '.strtolower(App\Services\ProjectSummary::STATUSES[$s]))->implode(', ') }}">
                        @foreach ($counts as $s => $n)@if ($n)<i style="flex:{{ $n }};background:{{ $colours[$s] }}"></i>@endif @endforeach
                    </div>
                    <div class="pg-key">@foreach ($counts as $s => $n)<span><i style="background:{{ $colours[$s] }};{{ $s === 'backlog' ? 'outline:1px solid var(--pg-border)' : '' }}"></i>{{ $n }} {{ strtolower(App\Services\ProjectSummary::STATUSES[$s]) }}</span>@endforeach</div>
                @else
                    <p class="pg-empty">No tasks yet.</p>
                @endif
                <div class="pg-card-head" style="margin-top:16px"><h2>People</h2><small>open tasks</small></div>
                @forelse ($summary->people()->take(6) as $person)
                    <div class="pg-row"><div><span class="pg-avatar" style="margin-right:6px;vertical-align:-6px" aria-hidden="true">{{ $person['initials'] }}</span>{{ $person['name'] }}</div><span class="{{ $person['overdue'] ? 'pg-neg' : '' }}">{{ $person['open'] }}@if ($person['overdue']), {{ $person['overdue'] }} late @endif</span></div>
                @empty
                    <p class="pg-empty">Nobody holds open work yet.</p>
                @endforelse
                @if ($summary->unassignedOpen())<p class="pg-help">{{ $summary->unassignedOpen() }} open {{ Str::plural('task', $summary->unassignedOpen()) }} unassigned.</p>@endif
            </section>

            <section class="pg-card pg-s7" aria-labelledby="talk-h">
                <div class="pg-card-head"><h2 id="talk-h">Discussion</h2></div>
                <livewire:projects.project-comments :project="$project" />
            </section>

            <div class="pg-s5" style="display:grid;gap:var(--pg-gap);align-content:start">
            @php
                $matrix = App\Services\RiskMatrix::forProject($project);
                $pill = ['low' => '', 'medium' => 'pg-pill--warning', 'high' => 'pg-pill--negative', 'critical' => 'pg-pill--negative pg-pill--solid'];
                $gov = collect()
                    ->concat($openGates->filter(fn ($g) => $g->status === 'held' || $g->due_date)->map(fn ($g) => ['ref' => $g->ref(), 'title' => $g->title, 'url' => route('projects.gates.show', [$project, $g]), 'band' => $g->status === 'held' || $g->overdue() ? 'high' : 'medium', 'label' => $g->status === 'held' ? 'Gate held' : ($g->overdue() ? 'Gate was due '.$g->due_date->format('j M') : 'Gate due '.$g->due_date->format('j M')), 'esc' => false, 'rank' => $g->status === 'held' || $g->overdue() ? 960 : 850]))
                    ->when($lateActions > 0, fn ($c) => $c->push(['ref' => '', 'title' => $lateActions.' late '.Str::plural('action', $lateActions), 'url' => route('projects.actions', $project), 'band' => 'high', 'label' => 'Past their date', 'esc' => false, 'rank' => 940]))
                    ->concat($waitingChanges->map(fn ($c) => ['ref' => $c->ref(), 'title' => $c->title, 'url' => route('projects.changes.show', [$project, $c]), 'band' => 'medium', 'label' => 'Waiting on '.($c->approver?->name ?? 'approval'), 'esc' => false, 'rank' => 900]))
                    ->concat($openDecisions->map(fn ($d) => ['ref' => $d->ref(), 'title' => $d->title, 'url' => route('projects.decisions.show', [$project, $d]), 'band' => $d->overdue() ? 'high' : 'medium', 'label' => $d->decide_by ? ($d->overdue() ? 'Decision was due '.$d->decide_by->format('j M') : 'Decide by '.$d->decide_by->format('j M')) : 'Needs deciding', 'esc' => false, 'rank' => $d->overdue() ? 950 : 800]))
                    ->concat($openIssues->map(fn ($i) => ['ref' => $i->ref(), 'title' => $i->title, 'url' => route('projects.issues.show', [$project, $i]), 'band' => $i->severity, 'label' => ucfirst($i->severity).' issue', 'esc' => $i->escalated_at !== null, 'rank' => ($i->escalated_at ? 1000 : 0) + $i->weight() + 50]))
                    ->concat($liveRisks->map(fn ($r) => ['ref' => $r->ref(), 'title' => $r->title, 'url' => route('projects.risks.show', [$project, $r]), 'band' => $r->band($matrix), 'label' => ucfirst($r->band($matrix)).' risk', 'esc' => $r->escalated_at !== null, 'rank' => ($r->escalated_at ? 1000 : 0) + ['low' => 0, 'medium' => 20, 'high' => 40, 'critical' => 60][$r->band($matrix)] + $r->currentScore()]))
                    ->sortByDesc('rank')->take(5);
            @endphp
            <section class="pg-card" aria-labelledby="gov-h">
                <div class="pg-card-head"><h2 id="gov-h">Governance</h2><small>{{ $liveRisks->count() }} live {{ Str::plural('risk', $liveRisks->count()) }}, {{ $openIssues->count() }} open {{ Str::plural('issue', $openIssues->count()) }}@if ($waitingChanges->count() + $openDecisions->count()), {{ $waitingChanges->count() + $openDecisions->count() }} waiting on a decision @endif</small></div>
                @forelse ($gov as $g)
                    <div class="pg-row">
                        <div style="min-width:0">@if ($g['ref'] !== '')<span class="pg-id">{{ $g['ref'] }}</span> @endif<a href="{{ $g['url'] }}">{{ $g['title'] }}</a>@if ($g['esc'])<div class="pg-sub pg-neg">Escalated</div>@endif</div>
                        <span class="pg-pill {{ $pill[$g['band']] ?? '' }}">{{ $g['label'] }}</span>
                    </div>
                @empty
                    <p class="pg-empty">No live risks, open issues or waiting decisions. <a href="{{ route('projects.risks', $project) }}">Open the risk register</a> to record what could get in the way.</p>
                @endforelse
                @if ($liveRisks->count() + $openIssues->count() + $waitingChanges->count() + $openDecisions->count() + $openGates->count() + ($lateActions > 0 ? 1 : 0) > $gov->count())<p class="pg-help" style="margin:8px 0 0"><a href="{{ route('projects.risks', $project) }}">View all</a></p>@endif
            </section>

            <section class="pg-card" aria-labelledby="act-h">
                <div class="pg-card-head"><h2 id="act-h">Recent activity</h2></div>
                @forelse ($activity as $line)
                    <div class="pg-act"><time datetime="{{ $line['at']->toIso8601String() }}">{{ $line['at']->format('j M, H:i') }}</time><span>@if ($line['who'])<b style="font-weight:500">{{ $line['who'] }}</b> @endif{{ $line['text'] }}</span></div>
                @empty
                    <p class="pg-empty">Changes to this project appear here as people make them.</p>
                @endforelse
            </section>
            </div>
        </div>
    </div>
</x-app-layout>
