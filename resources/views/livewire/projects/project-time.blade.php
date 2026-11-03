<div>
    @php
        $fmt = fn (int $m) => App\Support\Duration::format($m);
        $range = $start->isSameDay($end) ? $start->format('j M Y') : $start->format('j M').' to '.$end->format('j M Y');
        $share = $s['total'] ? (int) round($s['billable'] / $s['total'] * 100) : 0;
        $over = collect($estimates)->filter(fn ($r) => $r['over'] !== null && $r['over'] > 0);
        $weekMax = max(1, (int) collect($s['byWeek'])->max('minutes'));
        $statusList = app(App\Services\ProjectStatuses::class)->for($project);
        $statusName = fn ($task) => $statusList->firstWhere('key', app(App\Services\ProjectStatuses::class)->keyOf($task, $statusList))['name'] ?? $task->status;
    @endphp

    <form class="pg-filters" wire:submit.prevent aria-label="Choose the period">
        <label for="pt-period" class="pg-sr">Period</label>
        <select id="pt-period" wire:model.live="period" class="pg-select" style="width:auto">
            @foreach (App\Services\TimeReport::PERIODS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        @if ($period === 'custom')
            <label for="pt-from" class="pg-sr">From</label>
            <input id="pt-from" type="date" wire:model.live.debounce.500ms="from" class="pg-input" style="width:auto" value="{{ $start->toDateString() }}">
            <label for="pt-to" class="pg-sr">To</label>
            <input id="pt-to" type="date" wire:model.live.debounce.500ms="to" class="pg-input" style="width:auto" value="{{ $end->toDateString() }}">
        @endif
        <label class="pg-chip" style="cursor:pointer"><input type="checkbox" class="pg-check" wire:model.live="approved"> Approved weeks only</label>
        @if ($detail && $s['count'])
            <a href="{{ $csv }}" class="pg-btn pg-btn--secondary" style="margin-left:auto" download>Download as CSV</a>
        @endif
    </form>

    <p class="pg-finding" style="margin:0 0 var(--pg-gap)">
        @if ($s['total'] === 0)
            No {{ $approved ? 'approved ' : '' }}time logged from {{ $range }}. People log time on the <a href="{{ route('time.index') }}">Time</a> page or on a task.
        @else
            <b>{{ $fmt($s['total']) }}</b> logged from {{ $range }}, {{ $fmt($s['billable']) }} billable ({{ $share }}%), by {{ $s['people'] }} {{ Str::plural('person', $s['people']) }}{{ $approved ? ', approved weeks only' : '' }}.
        @endif
        @if ($over->isNotEmpty()) {{ $over->count() }} {{ Str::plural('task', $over->count()) }} {{ $over->count() === 1 ? 'is' : 'are' }} over {{ $over->count() === 1 ? 'its' : 'their' }} estimate.@endif
    </p>

    <div class="pg-grid">
        <section class="pg-table-wrap pg-s7" aria-labelledby="bt-h">
            <h2 id="bt-h" style="font-size:14px;font-weight:600;margin:0;padding:12px 16px 4px">By task</h2>
            @if ($s['byTask'])
                <table class="pg-table" style="min-width:0">
                    <thead><tr><th scope="col">Task</th><th scope="col" class="r">Time</th><th scope="col" class="r">Billable</th></tr></thead>
                    <tbody>
                        @foreach ($s['byTask'] as $r)
                            <tr><td class="{{ $r['task'] ? '' : 'pg-muted' }}">{{ $r['task'] ?? 'The project in general' }}</td><td data-label="Time" class="r">{{ $fmt($r['minutes']) }}</td><td data-label="Billable" class="r {{ $r['billable'] ? '' : 'pg-muted' }}">{{ $r['billable'] ? $fmt($r['billable']) : '–' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="pg-empty" style="padding:8px 16px 16px">Time by task for the period appears here.</p>
            @endif
        </section>

        <div class="pg-s5" style="display:grid;gap:var(--pg-gap);align-content:start">
            <section class="pg-card" aria-labelledby="bw-h">
                <div class="pg-card-head"><h2 id="bw-h">By week</h2><small>Weeks start on Monday</small></div>
                @forelse ($s['byWeek'] as $w)
                    <div class="pg-weekbar"><span>{{ $w['week']->format('j M') }}</span><div class="pg-meter" role="img" aria-label="Week of {{ $w['week']->format('j M') }}: {{ $fmt($w['minutes']) }}"><i style="width:{{ (int) round($w['minutes'] / $weekMax * 100) }}%"></i></div><b>{{ $fmt($w['minutes']) }}</b></div>
                @empty
                    <p class="pg-empty">Nothing in the period.</p>
                @endforelse
            </section>

            <section class="pg-card" aria-labelledby="bp-h">
                <div class="pg-card-head"><h2 id="bp-h">By person</h2></div>
                @if (! $detail)
                    <p class="pg-empty">Time by person is for the people who run this project and the business owner.</p>
                @elseif ($s['byPerson'])
                    <dl class="pg-kv">
                        @foreach ($s['byPerson'] as $p)<dt>{{ $p['name'] }}</dt><dd>{{ $fmt($p['minutes']) }}{{ $p['billable'] ? ', '.$fmt($p['billable']).' billable' : '' }}</dd>@endforeach
                    </dl>
                @else
                    <p class="pg-empty">Nobody logged time in the period.</p>
                @endif
            </section>
        </div>
    </div>

    <section class="pg-table-wrap" style="margin-top:var(--pg-gap)" aria-labelledby="ea-h">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;padding:12px 16px 4px;flex-wrap:wrap">
            <h2 id="ea-h" style="font-size:14px;font-weight:600;margin:0">Estimate against actual</h2>
            <small class="pg-help" style="margin:0">All time to date, not only the period</small>
        </div>
        @if ($estimates)
            <table class="pg-table pg-table--estimates">
                <thead><tr><th scope="col">Task</th><th scope="col">Status</th><th scope="col" class="r">Estimate</th><th scope="col" class="r">Logged</th><th scope="col" style="width:28%"><span class="pg-sr">Against the estimate</span></th><th scope="col" class="r">Difference</th></tr></thead>
                <tbody>
                    @foreach ($estimates as $r)
                        <tr>
                            <td class="pg-name"><a href="{{ route('projects.tasks.show', [$project, $r['task']]) }}">{{ $r['task']->title }}</a></td>
                            <td data-label="Status">{{ $statusName($r['task']) }}</td>
                            <td data-label="Estimate" class="r {{ $r['estimate'] ? '' : 'pg-muted' }}">{{ $r['estimate'] ? $fmt($r['estimate']) : 'None' }}</td>
                            <td data-label="Logged" class="r">{{ $fmt($r['logged']) }}</td>
                            <td data-label="Against">@if ($r['estimate'])<div class="pg-meter" role="img" aria-label="{{ (int) round($r['logged'] / $r['estimate'] * 100) }}% of the estimate"><i class="{{ $r['over'] > 0 ? 'is-over' : '' }}" style="width:{{ min(100, (int) round($r['logged'] / $r['estimate'] * 100)) }}%"></i></div>@endif</td>
                            <td data-label="Difference" class="r {{ ($r['over'] ?? 0) > 0 ? 'pg-neg' : 'pg-muted' }}">@if ($r['over'] === null)–@elseif ($r['over'] > 0){{ $fmt($r['over']) }} over @elseif ($r['over'] < 0){{ $fmt(-$r['over']) }} left @else On the estimate @endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="pg-empty" style="padding:8px 16px 16px">Give tasks an estimate on the task page to compare it with the time logged.</p>
        @endif
    </section>
</div>
