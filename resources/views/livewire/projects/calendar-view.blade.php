@php
    $icons = ['task' => 'radio_button_unchecked', 'milestone' => 'flag', 'meeting' => 'event', 'governance' => 'gavel'];
    $canChange = $project->canBeChangedBy(auth()->user());
    // The chosen day, or today while today's month is showing, is listed under the month.
    $selected = $day !== '' ? Illuminate\Support\Carbon::parse($day) : ($first->isSameMonth(now()) ? Illuminate\Support\Carbon::today() : null);
    $chip = function (array $e) use ($icons) {
        $state = $e['late'] ? 'late' : ($e['critical'] ? 'critical' : ($e['done'] ? 'done' : ''));
        $icon = $e['kind'] === 'task' && $e['done'] ? 'check_circle' : $icons[$e['kind']];
        $note = $e['late'] ? ', late' : ($e['critical'] ? ', on the critical path' : ($e['done'] ? ', done' : ''));

        return compact('state', 'icon', 'note');
    };
@endphp
<div>
    <section class="pg-card pg-cal" aria-labelledby="cal-h">
        <div class="pg-cal-head">
            <div class="pg-cal-nav">
                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm pg-btn--icon" wire:click="go(-1)" aria-label="Previous month"><span class="material-symbols-rounded" aria-hidden="true">chevron_left</span></button>
                <h2 id="cal-h">{{ $first->format('F Y') }}</h2>
                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm pg-btn--icon" wire:click="go(1)" aria-label="Next month"><span class="material-symbols-rounded" aria-hidden="true">chevron_right</span></button>
                <button type="button" class="pg-btn pg-btn--secondary pg-btn--sm" wire:click="go(0)">Today</button>
            </div>
            <div class="pg-cal-tools">
                <label for="cal-show" class="pg-sr">Show</label>
                <select id="cal-show" wire:model.live="show" class="pg-select pg-select--sm">
                    <option value="all">Everything</option>
                    @foreach (App\Services\ProjectCalendar::KINDS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    <option value="mine">Only mine</option>
                </select>
            </div>
        </div>
        <p class="pg-cal-finding">
            {{ $count === 0 ? 'Nothing dated in '.$first->format('F').'.' : $count.' dated '.Str::plural('item', $count).' in '.$first->format('F').'.' }}
            Blue is work on the critical path; red is past its date.@if ($canChange) Drag an open task to another day, or focus it and use the arrow keys, to move it with its length kept.@endif
        </p>
        @error('calendar')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
        @error('schedule')<p class="pg-error" role="alert">{{ $message }}</p>@enderror

        <table class="pg-cal-grid">
            <thead><tr>@foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)<th scope="col">{{ $d }}</th>@endforeach</tr></thead>
            <tbody>
                @for ($week = $gridStart->copy(); $week->lte($gridEnd); $week->addWeek())
                    <tr>
                        @for ($i = 0; $i < 7; $i++)
                            @php
                                $date = $week->copy()->addDays($i);
                                $iso = $date->toDateString();
                                $list = $events[$iso] ?? [];
                                $out = ! $date->isSameMonth($first);
                            @endphp
                            <td class="pg-cal-day {{ $out ? 'out' : '' }} {{ $iso === $today ? 'today' : '' }} {{ $selected && $iso === $selected->toDateString() ? 'picked' : '' }} {{ $date->isWeekend() ? 'wknd' : '' }}" data-cal-day="{{ $iso }}" wire:key="d-{{ $iso }}">
                                <button type="button" class="pg-cal-num" wire:click="pick('{{ $iso }}')" aria-label="{{ $date->format('l j F') }}{{ $list ? ', '.count($list).' '.Str::plural('item', count($list)) : '' }}" aria-pressed="{{ $selected && $iso === $selected->toDateString() ? 'true' : 'false' }}">{{ $date->day }}</button>
                                @if ($list)
                                    <ul class="pg-cal-items">
                                        @foreach (array_slice($list, 0, 3) as $e)
                                            @php $c = $chip($e); @endphp
                                            <li>
                                                <a href="{{ $e['url'] }}" class="pg-cal-chip {{ $c['state'] }}" title="{{ $e['type'] }}: {{ $e['title'] }}{{ $c['note'] }}"
                                                   @if ($e['movable']) draggable="true" data-cal-task="{{ $e['id'] }}" data-due="{{ $iso }}" @endif>
                                                    <span class="material-symbols-rounded" aria-hidden="true">{{ $c['icon'] }}</span>
                                                    <span class="t">@if ($e['time']){{ $e['time'] }} @endif{{ $e['title'] }}</span><span class="pg-sr">{{ $c['note'] }}</span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if (count($list) > 3)<button type="button" class="pg-cal-more" wire:click="pick('{{ $iso }}')">{{ count($list) - 3 }} more</button>@endif
                                    <span class="pg-cal-marks" aria-hidden="true">@foreach (array_slice($list, 0, 4) as $e)<i class="{{ $chip($e)['state'] }}"></i>@endforeach</span>
                                @endif
                            </td>
                        @endfor
                    </tr>
                @endfor
            </tbody>
        </table>
    </section>

    @if ($selected)
        @php $list = $events[$selected->toDateString()] ?? []; @endphp
        <section class="pg-card pg-cal-list" aria-labelledby="day-h" style="margin-top:var(--pg-gap)">
            <div class="pg-card-head"><h2 id="day-h">{{ $selected->format('l j F') }}</h2><small>{{ count($list) ? count($list).' '.Str::plural('item', count($list)) : 'Nothing dated' }}</small></div>
            @forelse ($list as $e)
                @php $c = $chip($e); @endphp
                <div class="pg-row">
                    <div><a href="{{ $e['url'] }}" class="{{ $c['state'] === 'late' ? 'pg-neg' : '' }}">@if ($e['time']){{ $e['time'] }} @endif{{ $e['title'] }}</a><div class="pg-sub">{{ $e['type'] }}{{ $c['note'] }}{{ $e['mine'] ? ', yours' : '' }}</div></div>
                    @if ($e['critical'])<span class="pg-pill pg-pill--critical">Critical path</span>@elseif ($e['late'])<span class="pg-pill pg-pill--negative">Late</span>@elseif ($e['done'])<span class="pg-pill">Done</span>@endif
                </div>
            @empty
                <p class="pg-empty">Nothing is due, planned or held on this day.</p>
            @endforelse
        </section>
    @endif
</div>
