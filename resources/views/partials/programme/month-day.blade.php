{{-- The chosen day under the month (R-34 P13b, shared by the project and business calendars). Needs $first, $gridStart, $gridEnd,
     $events (date => items), $selected (Carbon or null), $today (Y-m-d) and $showProject (bool). --}}
@php
    $icons = ['task' => 'radio_button_unchecked', 'milestone' => 'flag', 'meeting' => 'event', 'governance' => 'gavel'];
    $chip = function (array $e) use ($icons) {
        $state = $e['late'] ? 'late' : ($e['critical'] ? 'critical' : ($e['done'] ? 'done' : ''));
        $icon = $e['kind'] === 'task' && $e['done'] ? 'check_circle' : $icons[$e['kind']];
        $note = $e['late'] ? ', late' : ($e['critical'] ? ', on the critical path' : ($e['done'] ? ', done' : ''));

        return compact('state', 'icon', 'note');
    };
@endphp
    @if ($selected)
        @php $list = $events[$selected->toDateString()] ?? []; @endphp
        <section class="pg-card pg-cal-list" aria-labelledby="day-h" style="margin-top:var(--pg-gap)">
            <div class="pg-card-head"><h2 id="day-h">{{ $selected->format('l j F') }}</h2><small>{{ count($list) ? count($list).' '.Str::plural('item', count($list)) : 'Nothing dated' }}</small></div>
            @forelse ($list as $e)
                @php $c = $chip($e); @endphp
                <div class="pg-row">
                    <div><a href="{{ $e['url'] }}" class="{{ $c['state'] === 'late' ? 'pg-neg' : '' }}">@if ($e['time']){{ $e['time'] }} @endif{{ $e['title'] }}</a><div class="pg-sub">{{ $showProject && isset($e['project']) ? $e['project'].', '.Str::lcfirst($e['type']) : $e['type'] }}{{ $e['mine'] ? ', yours' : '' }}</div></div>
                    @if ($e['critical'])<span class="pg-pill pg-pill--critical">Critical path</span>@elseif ($e['late'])<span class="pg-pill pg-pill--negative">Late</span>@elseif ($e['done'])<span class="pg-pill">Done</span>@endif
                </div>
            @empty
                <p class="pg-empty">Nothing is due, planned or held on this day.</p>
            @endforelse
        </section>
    @endif
