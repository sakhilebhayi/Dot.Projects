<div>
    @php
        $fmt = fn (int $m) => App\Support\Duration::format($m);
        $weeks = $rows ? array_keys($rows[0]['weeks']) : [];
        $overRows = collect($rows)->map(fn ($r) => ['r' => $r, 'over' => collect($r['weeks'])->filter(fn ($w) => $w['minutes'] > $r['capacity'])])->filter(fn ($x) => $x['over']->isNotEmpty());
        $noEstimate = (int) collect($rows)->sum('noEstimate');
        $undated = (int) collect($rows)->sum('undated');
        $worst = $overRows->map(fn ($x) => ['name' => $x['r']['user']->name, 'by' => $x['over']->max(fn ($w) => $w['minutes'] - $x['r']['capacity'])])->sortByDesc('by')->first();
    @endphp

    <div class="pg-titlerow">
        <div>
            <h1 class="pg-title">Workload</h1>
            <p class="pg-finding">
                @if ($overRows->isEmpty())
                    {{ $oversees ? 'Nobody is' : 'You are not' }} planned over capacity in the next six weeks.
                @elseif ($oversees)
                    <b>{{ $overRows->count() }} {{ Str::plural('person', $overRows->count()) }} over capacity</b> in the next six weeks; {{ $worst['name'] }} by up to {{ $fmt((int) $worst['by']) }} in a week.
                @else
                    <b>You are planned over capacity</b> in {{ $overRows->first()['over']->count() }} of the next six weeks.
                @endif
                @if ($noEstimate || $undated) The picture is partial: {{ implode(' and ', array_filter([$noEstimate ? $noEstimate.' open '.Str::plural('task', $noEstimate).' '.($noEstimate === 1 ? 'has' : 'have').' no estimate' : null, $undated ? $fmt($undated).' of estimated work has no due date' : null])) }}.@endif
            </p>
        </div>
    </div>

    @if ($focused)
        @php
            $fr = $focused['row'];
            $fw = $fr['weeks'][$focused['week']];
        @endphp
        <section class="pg-card" style="margin-bottom:var(--pg-gap)" aria-labelledby="wf-h">
            <div class="pg-card-head"><h2 id="wf-h">{{ $fr['user']->name }}, week of {{ Illuminate\Support\Carbon::parse($focused['week'])->format('j M') }}</h2><small class="{{ $fw['minutes'] > $fr['capacity'] ? 'pg-neg' : '' }}">{{ $fmt($fw['minutes']) }} planned against {{ $fmt($fr['capacity']) }}</small></div>
            @forelse ($fw['items'] as $i)
                <div class="pg-row">
                    <div style="min-width:0">
                        @if ($i['project'] !== null)<a href="{{ route('projects.tasks.show', [$i['task']->project_id, $i['task']]) }}">{{ $i['task']->title }}</a><div class="pg-sub">{{ $i['project'] }}{{ $i['task']->due_date ? ', due '.$i['task']->due_date->format('j M') : '' }}{{ $i['task']->due_date && $i['task']->due_date->lt(today()) ? ' (overdue)' : '' }}</div>@else<span class="pg-muted">Work on a private project</span>@endif
                    </div>
                    <span>{{ $fmt($i['minutes']) }}</span>
                </div>
            @empty
                <p class="pg-empty">Nothing planned for this week.</p>
            @endforelse
            <p class="pg-help" style="margin:8px 0 0">Each task's estimate, less the time already logged on it, is spread over its working days up to its due date. To ease a week, move a due date or give the work to someone with room; nothing here moves on its own.</p>
            <div style="margin-top:8px"><button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('focus', '')">Close</button></div>
        </section>
    @endif

    <section class="pg-table-wrap" aria-labelledby="wl-h">
        <h2 id="wl-h" class="pg-sr">Planned load by person and week</h2>
        <table class="pg-table pg-table--load">
            <thead>
                <tr>
                    <th scope="col">Person</th>
                    <th scope="col" class="r">Last week, logged</th>
                    @foreach ($weeks as $w)<th scope="col" class="r">{{ Illuminate\Support\Carbon::parse($w)->format('j M') }}</th>@endforeach
                    <th scope="col">Not placed</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr wire:key="wl-{{ $r['user']->id }}">
                        <td class="pg-name">
                            {{ $r['user']->name }}
                            @if ($oversees && $editing === $r['user']->id)
                                <form wire:submit="saveCapacity" class="pg-cap-form">
                                    <label for="cap-{{ $r['user']->id }}" class="pg-sr">Project hours a week for {{ $r['user']->name }}</label>
                                    <input id="cap-{{ $r['user']->id }}" type="text" inputmode="decimal" wire:model="hours" class="pg-input" style="width:72px;min-height:30px">
                                    <label for="capn-{{ $r['user']->id }}" class="pg-sr">Why</label>
                                    <input id="capn-{{ $r['user']->id }}" type="text" wire:model="note" maxlength="120" class="pg-input" style="min-height:30px" placeholder="Part time, on site">
                                    <button type="submit" class="pg-btn pg-btn--primary pg-btn--sm">Save</button>
                                    <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('editing', null)">Cancel</button>
                                    @error('capacity')<span class="pg-error" style="width:100%">{{ $message }}</span>@enderror
                                </form>
                            @else
                                <span class="pg-sub">{{ $fmt($r['capacity']) }} a week{{ $r['note'] ? ', '.$r['note'] : '' }}@if ($oversees) <button type="button" class="pg-linkbtn" wire:click="editCapacity({{ $r['user']->id }})" aria-label="Change the capacity of {{ $r['user']->name }}">Change</button>@endif</span>
                            @endif
                        </td>
                        <td data-label="Last week" class="r {{ $r['lastWeek'] ? '' : 'pg-muted' }}">{{ $r['lastWeek'] ? $fmt($r['lastWeek']) : '–' }}</td>
                        @foreach ($r['weeks'] as $week => $w)
                            @php
                                $pct = $r['capacity'] > 0 ? (int) round($w['minutes'] / $r['capacity'] * 100) : ($w['minutes'] > 0 ? 999 : 0);
                                $over = $w['minutes'] > $r['capacity'];
                                $label = Illuminate\Support\Carbon::parse($week)->format('j M');
                            @endphp
                            <td data-label="{{ $label }}" class="r">
                                <button type="button" class="pg-load {{ $over ? 'is-over' : '' }} {{ $focus === $r['user']->id.'|'.$week ? 'is-on' : '' }}" wire:click="pick({{ $r['user']->id }}, '{{ $week }}')"
                                        aria-label="{{ $r['user']->name }}, week of {{ $label }}: {{ $fmt($w['minutes']) }} planned of {{ $fmt($r['capacity']) }}{{ $over ? ', over capacity' : '' }}">
                                    <span>{{ $w['minutes'] ? $fmt($w['minutes']) : '–' }}</span>
                                    <i style="width:{{ min(100, $pct) }}%"></i>
                                </button>
                            </td>
                        @endforeach
                        <td data-label="Not placed" class="{{ $r['noEstimate'] || $r['undated'] ? '' : 'pg-muted' }}">{{ implode(', ', array_filter([$r['noEstimate'] ? $r['noEstimate'].' '.Str::plural('task', $r['noEstimate']).' unestimated' : null, $r['undated'] ? $fmt($r['undated']).' undated' : null])) ?: '–' }}{{ $r['overdue'] ? '; '.$fmt($r['overdue']).' overdue in this week' : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
    <p class="pg-help" style="margin-top:10px">
        Planned load is each open task's estimate, less the time logged on it, spread over its working days (Monday to Friday) up to its due date; overdue work counts in this week. Capacity is project hours a week (40 unless {{ $oversees ? 'you change it' : 'the business owner changes it' }}). Leave and public holidays will come from Dot.HR once it can share them.
        @unless ($oversees) Only the business owner and admins see everyone's load.@endunless
    </p>
</div>
