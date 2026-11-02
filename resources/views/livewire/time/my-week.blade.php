<div>
    @php
        $fmt = fn (int $m) => App\Support\Duration::format($m);
        $entries = $this->entries;
        $total = (int) $entries->sum(fn ($e) => $e->elapsed());
        $billable = (int) $entries->where('billable', true)->sum(fn ($e) => $e->elapsed());
        $sheet = $this->sheet;
        $status = $sheet?->status ?? 'open';
        $locked = $sheet?->locked() ?? false;
        $days = collect(range(0, 6))->map(fn ($i) => $this->monday->copy()->addDays($i));
        $byDay = $entries->groupBy(fn ($e) => $e->work_date->toDateString());
        $isThisWeek = $this->monday->isSameDay(App\Services\TimeTracking::weekStart(today()));
        $statusPill = ['open' => '', 'submitted' => 'pg-pill--warning', 'approved' => 'pg-pill--positive', 'returned' => 'pg-pill--negative'];
    @endphp

    <div class="pg-titlerow">
        <div>
            <h1 class="pg-title">Your time<span class="pg-pill {{ $statusPill[$status] }}">{{ App\Models\Timesheet::STATUSES[$status] }}</span></h1>
            <p class="pg-finding">
                @if ($total === 0)
                    Nothing logged for the week of {{ $this->monday->format('j M') }} yet. Log time by hand, or start the timer when you begin.
                @else
                    <b>{{ $fmt($total) }}</b> in the week of {{ $this->monday->format('j M') }}, {{ $fmt($billable) }} billable, across {{ $entries->pluck('project_id')->unique()->count() }} {{ Str::plural('project', $entries->pluck('project_id')->unique()->count()) }}.
                @endif
                Only you, the people who run these projects and the business owner see your entries.
            </p>
        </div>
        <div class="pg-actions" style="align-items:center">
            <button type="button" class="pg-btn pg-btn--ghost" wire:click="shiftWeek(-1)" aria-label="Previous week">Previous</button>
            @unless ($isThisWeek)<button type="button" class="pg-btn pg-btn--secondary" wire:click="thisWeek">This week</button>@endunless
            <button type="button" class="pg-btn pg-btn--ghost" wire:click="shiftWeek(1)" aria-label="Next week">Next</button>
        </div>
    </div>

    @if ($running)
        <div class="pg-notice pg-timer" role="status" wire:poll.60s>
            <span><b>Timer running</b> on {{ $running->project?->name }}{{ $running->task ? ', '.$running->task->title : '' }} since {{ $running->running_since->format('H:i') }}: {{ $fmt($running->elapsed()) }} so far.</span>
            <button type="button" class="pg-btn pg-btn--primary pg-btn--sm" wire:click="stopTimer">Stop</button>
        </div>
    @endif

    @if ($review)
        <section class="pg-card" style="margin-bottom:var(--pg-gap)" aria-labelledby="rev-h">
            <div class="pg-card-head"><h2 id="rev-h">{{ $review->user->name }}, week of {{ $review->week_start->format('j M') }}</h2><small>{{ $fmt((int) $reviewEntries->sum('minutes')) }}, {{ $fmt((int) $reviewEntries->where('billable', true)->sum('minutes')) }} billable</small></div>
            <table class="pg-table" style="min-width:0">
                <thead><tr><th scope="col">Day</th><th scope="col">Project and task</th><th scope="col">Note</th><th scope="col" class="r">Time</th></tr></thead>
                <tbody>
                    @foreach ($reviewEntries as $e)
                        <tr><td data-label="Day">{{ $e->work_date->format('D j M') }}</td><td class="pg-name">{{ $e->project?->name }}@if ($e->task)<span class="pg-sub">{{ $e->task->title }}</span>@endif</td><td data-label="Note" class="{{ $e->note ? '' : 'pg-muted' }}">{{ $e->note ?? '–' }}{{ $e->billable ? ' (billable)' : '' }}</td><td data-label="Time" class="r">{{ $fmt((int) $e->minutes) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <div class="pg-form" style="margin-top:12px;gap:8px">
                <label for="rev-note" class="pg-label" style="margin:0">If it needs changing, say what</label>
                <textarea id="rev-note" wire:model="returnNote" rows="2" maxlength="2000" class="pg-textarea"></textarea>
                @error('return_note')<p class="pg-error">{{ $message }}</p>@enderror
                @error('timesheet')<p class="pg-error">{{ $message }}</p>@enderror
                <div class="pg-actions"><button type="button" class="pg-btn pg-btn--primary" wire:click="approveSheet({{ $review->id }})">Approve the week</button><button type="button" class="pg-btn pg-btn--secondary" wire:click="returnSheet({{ $review->id }})">Return it</button><button type="button" class="pg-btn pg-btn--ghost" wire:click="$set('approve', '')">Close</button></div>
            </div>
        </section>
    @endif

    <div class="pg-grid">
        <div class="pg-s8" style="display:grid;gap:var(--pg-gap);align-content:start">
            <section class="pg-card" aria-label="The week by day">
                <ol class="pg-days">
                    @foreach ($days as $d)
                        @php $m = (int) ($byDay->get($d->toDateString()) ?? collect())->sum(fn ($e) => $e->elapsed()); @endphp
                        <li class="{{ $d->isToday() ? 'is-today' : '' }} {{ $d->isWeekend() ? 'is-weekend' : '' }}"><span>{{ $d->format('D j') }}</span><b>{{ $m ? $fmt($m) : '–' }}</b></li>
                    @endforeach
                </ol>
            </section>

            <section class="pg-table-wrap" aria-labelledby="ent-h">
                <h2 id="ent-h" style="font-size:14px;font-weight:600;margin:0;padding:12px 16px 4px">Entries</h2>
                @if ($entries->isEmpty())
                    <p class="pg-empty" style="padding:8px 16px 16px">Entries for this week appear here, by day.</p>
                @else
                    <table class="pg-table pg-table--time">
                        <thead><tr><th scope="col">Project and task</th><th scope="col">Day</th><th scope="col">Note</th><th scope="col" class="r">Time</th><th scope="col"><span class="pg-sr">Change</span></th></tr></thead>
                        <tbody>
                            @foreach ($entries as $e)
                                <tr wire:key="te-{{ $e->id }}" class="{{ $editing === $e->id ? 'is-editing' : '' }}">
                                    <td class="pg-name">{{ $e->project?->name }}@if ($e->task)<span class="pg-sub">{{ $e->task->title }}</span>@endif</td>
                                    <td data-label="Day">{{ $e->work_date->format('D j M') }}</td>
                                    <td data-label="Note" class="{{ $e->note ? '' : 'pg-muted' }}">{{ $e->note ?? '–' }}@if ($e->billable)<span class="pg-sub">Billable</span>@endif</td>
                                    <td data-label="Time" class="r">{{ $e->running() ? 'Running, '.$fmt($e->elapsed()) : $fmt((int) $e->minutes) }}</td>
                                    <td data-label="Status">@if (! $locked && ! $e->running())<span class="pg-actions" style="justify-content:flex-end;flex-wrap:nowrap"><button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="edit({{ $e->id }})">Edit</button><button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="delete({{ $e->id }})" wire:confirm="Remove this entry?" aria-label="Remove the entry on {{ $e->work_date->format('j M') }}">Remove</button></span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                @error('entry')<p class="pg-error" style="padding:0 16px 12px">{{ $message }}</p>@enderror
            </section>
        </div>

        <aside class="pg-s4" style="display:grid;gap:var(--pg-gap);align-content:start">
            <section class="pg-card" aria-labelledby="log-h">
                <div class="pg-card-head"><h2 id="log-h">{{ $editing ? 'Change the entry' : 'Log time' }}</h2></div>
                @if ($this->projects->isEmpty())
                    <p class="pg-empty">You are not working on any open project in this business, so there is nowhere to log time.</p>
                @elseif ($locked && ! $editing && $isThisWeek)
                    <p class="pg-empty">This week is {{ mb_strtolower(App\Models\Timesheet::STATUSES[$status]) }}, so its time is fixed.</p>
                @else
                    <form wire:submit="save" class="pg-form" style="gap:10px">
                        <div class="pg-field">
                            <label for="tl-project" class="pg-label">Project</label>
                            <select id="tl-project" wire:model.live="project" class="pg-select" @disabled($editing)>
                                @foreach ($this->projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                            </select>
                            @error('project')<p class="pg-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="pg-field">
                            <label for="tl-task" class="pg-label">Task (optional)</label>
                            <select id="tl-task" wire:model="task" class="pg-select">
                                <option value="">The project in general</option>
                                @foreach ($tasks as $t)<option value="{{ $t->id }}">{{ Str::limit($t->title, 50) }}</option>@endforeach
                            </select>
                            @error('task_id')<p class="pg-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="pg-form-row">
                            <div class="pg-field">
                                <label for="tl-date" class="pg-label">Day</label>
                                <input id="tl-date" type="date" wire:model="date" max="{{ today()->toDateString() }}" class="pg-input">
                                @error('work_date')<p class="pg-error">{{ $message }}</p>@enderror
                            </div>
                            <div class="pg-field">
                                <label for="tl-hours" class="pg-label">Time</label>
                                <input id="tl-hours" type="text" inputmode="decimal" wire:model="hours" class="pg-input" placeholder="1:30 or 1.5" aria-describedby="tl-hours-help">
                                @error('minutes')<p class="pg-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <p id="tl-hours-help" class="pg-help" style="margin:-4px 0 0">Hours and minutes: 1:30, 1.5, 90m or 1h 30m.</p>
                        <div class="pg-field">
                            <label for="tl-note" class="pg-label">What it was for (optional)</label>
                            <input id="tl-note" type="text" wire:model="note" maxlength="500" class="pg-input">
                            @error('note')<p class="pg-error">{{ $message }}</p>@enderror
                        </div>
                        <label class="pg-radio"><input type="checkbox" wire:model="billable"> Billable to the client</label>
                        <div class="pg-actions">
                            <button type="submit" class="pg-btn pg-btn--primary">{{ $editing ? 'Save the change' : 'Log it' }}</button>
                            @if ($editing)
                                <button type="button" class="pg-btn pg-btn--ghost" wire:click="cancelEdit">Cancel</button>
                            @elseif (! $running)
                                <button type="button" class="pg-btn pg-btn--secondary" wire:click="startTimer">Start the timer</button>
                            @endif
                        </div>
                    </form>
                @endif
            </section>

            <section class="pg-card" aria-labelledby="ts-h">
                <div class="pg-card-head"><h2 id="ts-h">Timesheet</h2><span class="pg-pill {{ $statusPill[$status] }}">{{ App\Models\Timesheet::STATUSES[$status] }}</span></div>
                @if ($status === 'submitted')
                    <p class="pg-empty">Sent to {{ $sheet->approver?->name ?? 'the approver' }} on {{ $sheet->submitted_at?->format('j M') }}. The week's time is fixed unless it comes back.</p>
                @elseif ($status === 'approved')
                    <p class="pg-empty">Approved by {{ $sheet->decider?->name ?? 'the approver' }} on {{ $sheet->decided_at?->format('j M') }}.</p>
                @else
                    @if ($status === 'returned')<p class="pg-help pg-neg" style="margin:0 0 8px;white-space:pre-line"><b>Returned by {{ $sheet->decider?->name ?? 'the approver' }}:</b> {{ $sheet->return_note }}</p>@endif
                    @if ($total === 0)
                        <p class="pg-empty">Log time in the week before sending it for approval.</p>
                    @elseif ($approvers->isEmpty())
                        <p class="pg-empty">Nobody can approve this week yet: someone who runs one of these projects, or the business owner, approves it.</p>
                    @else
                        <form wire:submit="submit" class="pg-form" style="gap:8px">
                            <label for="ts-appr" class="pg-label" style="margin:0">Who approves it</label>
                            <select id="ts-appr" wire:model="approver" class="pg-select">
                                <option value="">Choose</option>
                                @foreach ($approvers as $a)<option value="{{ $a->id }}">{{ $a->name }}{{ $a->id === auth()->id() ? ', you' : '' }}</option>@endforeach
                            </select>
                            @error('approver')<p class="pg-error">{{ $message }}</p>@enderror
                            @error('timesheet')<p class="pg-error">{{ $message }}</p>@enderror
                            <div><button type="submit" class="pg-btn pg-btn--secondary">Send the week for approval</button></div>
                        </form>
                    @endif
                @endif
            </section>

            @if ($this->waiting->isNotEmpty())
                <section class="pg-card" aria-labelledby="wait-h">
                    <div class="pg-card-head"><h2 id="wait-h">Waiting for you</h2><small>{{ $this->waiting->count() }}</small></div>
                    @foreach ($this->waiting as $w)
                        <div class="pg-row"><span>{{ $w->user->name }}<span class="pg-sub" style="display:block">Week of {{ $w->week_start->format('j M') }}</span></span><button type="button" class="pg-btn pg-btn--secondary pg-btn--sm" wire:click="$set('approve', '{{ $w->id }}')">Review</button></div>
                    @endforeach
                </section>
            @endif
        </aside>
    </div>
</div>
