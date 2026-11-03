{{-- The month (R-34 P13b, shared by the project and business calendars). Needs $first, $gridStart, $gridEnd,
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
                                                <a href="{{ $e['url'] }}" class="pg-cal-chip {{ $c['state'] }}" title="{{ $showProject && isset($e['project']) ? $e['project'].', ' : '' }}{{ $e['type'] }}: {{ $e['title'] }}{{ $c['note'] }}"
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
