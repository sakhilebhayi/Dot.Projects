{{-- One dashboard widget's contents. Needs $w (DashboardWidget) and $d (DashboardWidgets::data for the reader). --}}
@if ($d['gone'] ?? false)
    <p class="pg-empty">The portfolio this widget showed has been removed. Arrange the dashboard to change or remove it.</p>
@elseif ($w->kind === 'report')
    @if ($d['missing'])
        <p class="pg-empty">This report is not shared with you, or it has been deleted.</p>
    @else
        @php $numeric = ['money', 'int', 'percent', 'ratio', 'hours', 'days']; @endphp
        @if ($d['rows'] === [])
            <p class="pg-empty">Nothing matches this report's filters.</p>
        @else
            <div class="pg-widget-table">
                <table class="pg-table pg-table--report pg-table--widget">
                    <thead><tr>@foreach ($d['columns'] as $c)<th scope="col" class="{{ in_array($c['type'], $numeric, true) ? 'r' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($d['rows'] as $i => $row)
                            <tr>
                                @foreach ($d['columns'] as $c)
                                    @if ($loop->first)
                                        <td class="pg-name">@if ($d['links'][$i] ?? null)<a href="{{ $d['links'][$i] }}">{{ App\Services\Reporting::show($row[$c['key']] ?? null, $c['type']) }}</a>@else{{ App\Services\Reporting::show($row[$c['key']] ?? null, $c['type']) }}@endif</td>
                                    @else
                                        <td data-label="{{ $c['label'] }}" class="{{ in_array($c['type'], $numeric, true) ? 'r' : '' }}{{ ($row[$c['key']] ?? null) === null ? ' pg-muted' : '' }}">{{ App\Services\Reporting::show($row[$c['key']] ?? null, $c['type']) }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p class="pg-widget-foot"><a href="{{ $d['url'] }}">{{ $d['total'] > count($d['rows']) ? 'All '.$d['total'].' rows in Reports' : 'Open in Reports' }}</a>@if ($d['notes'] && str_contains(implode(' ', $d['notes']), 'you do not see'))<span class="pg-muted">Some of it is not shown to you.</span>@endif</p>
    @endif
@elseif ($w->kind === 'health')
    @php $c = $d['counts']; @endphp
    @if ($d['total'] === 0)
        <p class="pg-empty">No open project here.</p>
    @else
        <p class="pg-widget-lead"><b>{{ $d['total'] }} {{ Str::plural('project', $d['total']) }}</b>: {{ $c['on_track'] }} on track, {{ $c['at_risk'] }} at risk, {{ $c['off_track'] }} off track{{ $c['delayed'] ? '; '.$c['delayed'].' delayed' : '' }}.</p>
        <div class="pg-healthbar" role="img" aria-label="{{ $c['on_track'] }} on track, {{ $c['at_risk'] }} at risk, {{ $c['off_track'] }} off track">
            @foreach (['off_track', 'at_risk', 'on_track'] as $h)@if ($c[$h])<i class="h-{{ $h }}" style="flex:{{ $c[$h] }}"></i>@endif @endforeach
        </div>
        @forelse ($d['intervene'] as $iv)
            <div class="pg-row"><div style="min-width:0"><a href="{{ route('projects.show', $iv['project']) }}">{{ $iv['project']->name }}</a><div class="pg-sub">{{ implode('; ', $iv['reasons']) }}</div></div></div>
        @empty
            <p class="pg-empty">Nothing needs intervention.</p>
        @endforelse
    @endif
@elseif ($w->kind === 'roadmap')
    @include('partials.programme.roadmap', ['rm' => $d['roadmap'], 'k' => 'w'.$w->id])
@elseif ($w->kind === 'risks')
    @include('partials.programme.major-risks', ['risks' => $d['risks'], 'k' => 'w'.$w->id])
@elseif ($w->kind === 'links')
    @include('partials.programme.project-links', ['links' => $d['links'], 'k' => 'w'.$w->id])
@elseif ($w->kind === 'my_work')
    <p class="pg-widget-lead">{{ $d['open'] }} open {{ Str::plural('task', $d['open']) }} given to you{{ $d['overdue'] ? ', '.$d['overdue'].' overdue' : '' }}.</p>
    @forelse ($d['tasks'] as $t)
        <div class="pg-row" wire:key="w{{ $w->id }}-t{{ $t->id }}">
            <div style="min-width:0"><a href="{{ route('projects.tasks.show', [$t->project_id, $t]) }}">{{ $t->title }}</a><div class="pg-sub">{{ $t->project->name }}{{ $t->isBlocked() ? ', waiting on other work' : '' }}</div></div>
            <span class="{{ $t->due_date->isPast() && ! $t->due_date->isToday() ? 'pg-neg' : '' }}">{{ $t->due_date->isToday() ? 'Today' : $t->due_date->format('j M') }}</span>
        </div>
    @empty
        <p class="pg-empty">Nothing of yours is overdue or due in the next two weeks.</p>
    @endforelse
    @if ($d['more'])<p class="pg-widget-foot">{{ $d['more'] }} more due in the next two weeks.</p>@endif
@elseif ($w->kind === 'my_time')
    @php
        $pct = $d['capacity'] > 0 ? min(100, (int) round($d['minutes'] / $d['capacity'] * 100)) : 0;
        $h = fn (int $m) => App\Services\Reporting::show($m, 'hours');
    @endphp
    <p class="pg-widget-lead"><b>{{ $h($d['minutes']) }}</b> logged in the week of {{ $d['week']->format('j M') }}, against {{ $h($d['capacity']) }} of capacity.</p>
    <div class="pg-meter" role="img" aria-label="{{ $d['capacity'] > 0 ? (int) round($d['minutes'] / $d['capacity'] * 100) : 0 }}% of capacity logged"><i class="{{ $d['minutes'] > $d['capacity'] ? 'is-over' : '' }}" style="width:{{ $pct }}%"></i></div>
    <dl class="pg-kv">
        <dt>Timesheet</dt><dd>{{ $d['status'] }}</dd>
        @if ($d['running'])<dt>Timer</dt><dd>Running now; it counts when you stop it.</dd>@endif
    </dl>
    <p class="pg-widget-foot"><a href="{{ route('time.index') }}">Open your week</a></p>
@endif
