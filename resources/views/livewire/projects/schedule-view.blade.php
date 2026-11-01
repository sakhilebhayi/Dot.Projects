<div>
    @php
        [$from, $to] = $schedule->range();
        $days = (int) $from->diffInDays($to) + 1;
        $x = fn ($d) => (int) $from->diffInDays($d) * $ppd;
        $today = Illuminate\Support\Carbon::today();
        $critical = $schedule->critical();
        $forecast = $schedule->forecastFinish();
        $target = $project->due_date;
        $baseDue = isset($base['project'][$project->id]) ? $base['project'][$project->id]->due_date : null;
        $byMilestone = $schedule->tasks->filter(fn ($t) => $t->due_date)->groupBy(fn ($t) => (int) $t->milestone_id);
        $groups = $schedule->milestones->map(fn ($m) => ['m' => $m, 'tasks' => $byMilestone->get($m->id, collect())]);
        if ($byMilestone->has(0)) { $groups->push(['m' => null, 'tasks' => $byMilestone->get(0)]); }
        $months = []; for ($m = $from->copy()->startOfMonth(); $m->lte($to); $m->addMonth()) { $months[] = $m->copy(); }
        $weeks = []; for ($w = $from->copy(); $w->lte($to); $w->addWeek()) { $weeks[] = $w->copy(); }
    @endphp

    <section class="pg-card" style="margin-bottom:var(--pg-gap)" aria-labelledby="sched-h">
        <div class="pg-titlerow" style="margin:0;align-items:center">
            <div>
                <h2 id="sched-h" class="pg-sr">Schedule summary</h2>
                <p class="pg-finding" style="margin:0">
                    @if ($forecast === null)
                        No task has a due date yet, so there is nothing to draw. Give tasks dates on the board or the task page.
                    @else
                        Work as planned finishes <b>{{ $forecast->format('j M Y') }}</b>@if ($target){{ $forecast->gt($target) ? ', '.(int) $target->diffInDays($forecast).' '.Str::plural('day', (int) $target->diffInDays($forecast)).' after the due date of '.$target->format('j M') : ', within the due date of '.$target->format('j M') }}@endif.
                        @if ($critical->isEmpty())
                            No open task is critical: everything has room before the due date{{ $schedule->conflictCount() ? ', but '.$schedule->conflictCount().' '.Str::plural('dependency', $schedule->conflictCount()).' '.($schedule->conflictCount() === 1 ? 'is' : 'are').' broken' : '' }}.
                        @else
                            {{ $critical->count() }} open {{ Str::plural('task', $critical->count()) }} {{ $critical->count() === 1 ? 'is' : 'are' }} on the critical path{{ $schedule->conflictCount() ? ', '.$schedule->conflictCount().' '.Str::plural('dependency', $schedule->conflictCount()).' broken' : '' }}.
                        @endif
                        @if ($chosen && $baseDue) Baseline {{ $chosen->number }} was due {{ $baseDue->format('j M') }}. @endif
                    @endif
                </p>
            </div>
            <div class="pg-actions" style="align-items:center">
                <span class="pg-chip" role="group" aria-label="Zoom" style="padding:2px">
                    <button type="button" class="pg-btn pg-btn--sm {{ $zoom === 'weeks' ? 'pg-btn--secondary' : 'pg-btn--ghost' }}" wire:click="$set('zoom', 'weeks')" aria-pressed="{{ $zoom === 'weeks' ? 'true' : 'false' }}">Weeks</button>
                    <button type="button" class="pg-btn pg-btn--sm {{ $zoom === 'months' ? 'pg-btn--secondary' : 'pg-btn--ghost' }}" wire:click="$set('zoom', 'months')" aria-pressed="{{ $zoom === 'months' ? 'true' : 'false' }}">Months</button>
                </span>
                @if ($baselines->isNotEmpty())
                    <label for="sched-baseline" class="pg-sr">Compare with baseline</label>
                    <select id="sched-baseline" wire:model.live="baseline" class="pg-select" style="width:auto">
                        @foreach ($baselines as $b)<option value="{{ $b->id }}">Baseline {{ $b->number }}, {{ $b->created_at->format('j M Y') }}</option>@endforeach
                    </select>
                @endif
                @if ($canEdit)
                    <details class="pg-menu">
                        <summary class="pg-btn pg-btn--secondary">Set baseline</summary>
                        <div class="pg-menu-panel" style="width:320px;padding:12px">
                            <form wire:submit="setBaseline" class="pg-form" style="gap:8px">
                                <p class="pg-help" style="margin:0">Saves today's dates as baseline {{ ($baselines->max('number') ?? 0) + 1 }}, so later changes can be measured against them.</p>
                                <label for="sched-note" class="pg-label" style="margin:0">Note (optional)</label>
                                <input id="sched-note" type="text" wire:model="baselineNote" maxlength="255" class="pg-input" placeholder="Agreed with the sponsor">
                                <button type="submit" class="pg-btn pg-btn--primary">Save baseline</button>
                            </form>
                        </div>
                    </details>
                @endif
            </div>
        </div>
        @error('schedule')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
    </section>

    @if ($forecast !== null)
        <div class="pg-gantt" x-data x-init="$el.scrollLeft = Math.max(0, {{ $x($today) }} - 7 * {{ $ppd }})" role="region" aria-label="Timeline from {{ $from->format('j M Y') }} to {{ $to->format('j M Y') }}" tabindex="0">
            <div class="pg-gantt-inner" style="width:calc(var(--g-names) + {{ $days * $ppd }}px);--ppd:{{ $ppd }}px;--week:{{ $ppd * 7 }}px">
                <div class="pg-g-row pg-g-head">
                    <div class="pg-g-name"></div>
                    <div class="pg-g-track">
                        @foreach ($months as $m)
                            @if ($m->gte($from))<span class="pg-g-month" style="left:{{ $x($m) }}px">{{ $m->format($m->month === 1 ? 'M Y' : 'M') }}</span>@endif
                        @endforeach
                        @if ($zoom === 'weeks')
                            @foreach ($weeks as $w)<span class="pg-g-week" style="left:{{ $x($w) }}px">{{ $w->format('j') }}</span>@endforeach
                        @endif
                    </div>
                </div>
                <div class="pg-g-body">
                    <span class="pg-g-today" style="left:calc(var(--g-names) + {{ $x($today) + intdiv($ppd, 2) }}px)" title="Today, {{ $today->format('j M') }}"></span>
                    @foreach ($groups as $g)
                        @php $m = $g['m']; @endphp
                        <div class="pg-g-row pg-g-group">
                            <div class="pg-g-name"><b>{{ $m?->title ?? 'No milestone' }}</b></div>
                            <div class="pg-g-track">
                                @if ($m && $m->due_date)
                                    @php $mf = $schedule->milestoneForecast($m); @endphp
                                    @php $mb = isset($base['milestone'][$m->id]) ? $base['milestone'][$m->id]->due_date : null; @endphp
                                    @if ($mb)<span class="pg-g-diamond is-base" style="left:{{ $x($mb) + intdiv($ppd, 2) }}px" title="Baseline {{ $chosen->number }}: {{ $mb->format('j M') }}"></span>@endif
                                    <span class="pg-g-diamond" style="left:{{ $x($m->due_date) + intdiv($ppd, 2) }}px" title="{{ $m->title }}, due {{ $m->due_date->format('j M') }}"></span>
                                    @if ($mf && $mf->gt($m->due_date))
                                        <span class="pg-g-diamond is-late" style="left:{{ $x($mf) + intdiv($ppd, 2) }}px" title="Its last task is due {{ $mf->format('j M') }}, {{ (int) $m->due_date->diffInDays($mf) }} days after the milestone"></span>
                                    @endif
                                @endif
                            </div>
                        </div>
                        @foreach ($g['tasks'] as $t)
                            @php
                                $r = $schedule->row($t);
                                $left = $x($r['start']);
                                $width = ((int) $r['start']->diffInDays($r['finish']) + 1) * $ppd;
                                $bt = $base['task'][$t->id] ?? null;
                                $cls = $t->status === 'done' ? 'is-done' : ($t->isBlocked() ? 'is-blocked' : '').($r['critical'] ? ' is-critical' : '');
                                $label = $t->title.', '.$r['start']->format('j M').' to '.$r['finish']->format('j M').($r['critical'] ? ', critical' : '').($r['float'] !== null && ! $r['critical'] ? ', '.$r['float'].' days of float' : '');
                            @endphp
                            <div class="pg-g-row" wire:key="g-{{ $t->id }}">
                                <div class="pg-g-name"><a href="{{ route('projects.tasks.show', [$project, $t]) }}" title="{{ $t->title }}">{{ Str::limit($t->title, 34) }}</a>@if ($r['conflicts'])<span class="pg-g-flag" title="{{ implode(' ', $r['conflicts']) }}">Conflict</span>@endif</div>
                                <div class="pg-g-track">
                                    @if ($bt && $bt->due_date)
                                        @php $bs = $bt->start_date ?? $bt->due_date; @endphp
                                        <span class="pg-g-base" style="left:{{ $x($bs) }}px;width:{{ ((int) $bs->diffInDays($bt->due_date) + 1) * $ppd }}px" title="Baseline {{ $chosen->number }}: {{ $bs->format('j M') }} to {{ $bt->due_date->format('j M') }}"></span>
                                    @endif
                                    <div class="pg-g-bar {{ $cls }}" data-bar="{{ $t->id }}" wire:key="bar-{{ $t->id }}-{{ $r['start']->toDateString() }}-{{ $r['finish']->toDateString() }}-{{ $ppd }}" style="left:{{ $left }}px;width:{{ $width }}px"
                                         x-data="pgBar({ id: {{ $t->id }}, start: '{{ $r['start']->toDateString() }}', due: '{{ $r['finish']->toDateString() }}', ppd: {{ $ppd }}, width: {{ $width }}, editable: {{ $canEdit && $t->status !== 'done' ? 'true' : 'false' }} })"
                                         x-bind:style="shift()" x-on:pointermove="move($event)" x-on:pointerup="up()" x-on:pointercancel="up()"
                                         role="slider" tabindex="0" aria-label="{{ $label }}" aria-valuetext="{{ $r['start']->format('j M') }} to {{ $r['finish']->format('j M') }}"
                                         @if ($canEdit && $t->status !== 'done') x-on:keydown="key($event)" @endif title="{{ $label }}{{ $r['conflicts'] ? '. '.implode(' ', $r['conflicts']) : '' }}">
                                        <span class="pg-g-grip" x-on:pointerdown="down($event, 'move')"></span>
                                        @if ($canEdit && $t->status !== 'done')<span class="pg-g-end" x-on:pointerdown.stop="down($event, 'end')" aria-hidden="true"></span>@endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </div>
        </div>
        <div class="pg-key" style="margin-top:10px">
            <span><i style="background:var(--pg-critical)"></i>Critical path: any slip moves the finish</span>
            <span><i style="background:var(--pg-bar-active)"></i>Has float</span>
            <span><i style="background:var(--pg-bar-done)"></i>Done</span>
            <span><i style="background:repeating-linear-gradient(135deg,var(--pg-bar-active) 0 2px,transparent 2px 5px);outline:1px solid var(--pg-bar-active)"></i>Blocked</span>
            @if ($chosen)<span><i style="height:3px;background:var(--pg-muted)"></i>Baseline {{ $chosen->number }}</span>@endif
            @if ($canEdit)<span>Drag a bar to move it, drag its end to change the due date. With the keyboard: arrows, and Shift with arrows.</span>@endif
        </div>
    @endif

    @if ($schedule->unscheduled()->isNotEmpty())
        <section class="pg-card" style="margin-top:var(--pg-gap)" aria-labelledby="unsched-h">
            <div class="pg-card-head"><h2 id="unsched-h">Not on the schedule</h2><small>{{ $schedule->unscheduled()->count() }} without a due date</small></div>
            @foreach ($schedule->unscheduled()->take(8) as $t)
                <div class="pg-row"><a href="{{ route('projects.tasks.show', [$project, $t]) }}">{{ $t->title }}</a><span class="pg-sub">{{ App\Services\ProjectSummary::STATUSES[$t->status] ?? $t->status }}</span></div>
            @endforeach
            @if ($schedule->unscheduled()->count() > 8)<p class="pg-help">And {{ $schedule->unscheduled()->count() - 8 }} more in the <a href="{{ route('projects.work', $project) }}">work view</a>.</p>@endif
        </section>
    @endif
</div>
