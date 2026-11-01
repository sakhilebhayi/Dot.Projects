<div>
    @php
        $waiting = $this->all->where('status', 'submitted')->count();
        $drafts = $this->all->where('status', 'draft')->count();
        $accepted = $this->all->filter(fn ($c) => $c->accepted());
        $days = (int) $accepted->sum('schedule_days');
        $cents = (int) $accepted->sum('cost_cents');
        $statusPill = ['draft' => '', 'submitted' => 'pg-pill--warning', 'approved' => 'pg-pill--positive', 'implemented' => 'pg-pill--positive', 'rejected' => 'pg-pill--negative', 'withdrawn' => ''];
        $daysText = fn (?int $d) => $d === null ? null : ($d === 0 ? 'No change' : ($d > 0 ? '+'.$d.' '.Str::plural('day', $d) : '−'.abs($d).' '.Str::plural('day', abs($d))));
    @endphp

    <div class="pg-titlerow" style="margin-top:0">
        <p class="pg-finding" style="margin:0">
            @if ($this->all->isEmpty())
                No change requests. When scope, dates, money or people need to move from what was agreed, raise a change so the decision is on record.
            @else
                @if ($waiting)<b>{{ $waiting }} {{ Str::plural('change', $waiting) }} {{ $waiting === 1 ? 'waits' : 'wait' }} for approval</b>{{ $drafts ? ', '.$drafts.' '.Str::plural('draft', $drafts).' not yet sent' : '' }}.@elseif ($drafts){{ $drafts }} {{ Str::plural('draft', $drafts) }} not yet sent for approval.@else Nothing waits for approval.@endif
                @if ($accepted->isNotEmpty())
                    @php
                        $effects = array_filter([
                            $days !== 0 ? 'move the finish '.abs($days).' '.Str::plural('day', abs($days)).($days > 0 ? ' later' : ' earlier') : null,
                            $cents !== 0 ? ($cents > 0 ? 'add '.App\Support\Money::rand($cents).' to the cost' : 'save '.App\Support\Money::rand(abs($cents))) : null,
                        ]);
                    @endphp
                    Approved changes {{ $effects ? implode(' and ', $effects) : 'leave the dates and the cost as planned' }}.
                @endif
            @endif
        </p>
        @if ($canEdit)
            <div class="pg-actions"><a href="#add-change" class="pg-btn pg-btn--primary" x-data x-on:click.prevent="document.getElementById('add-change').open = true; $nextTick(() => document.getElementById('c-title').focus())">Raise a change</a></div>
        @endif
    </div>

    @if ($canEdit)
        <details id="add-change" class="pg-card pg-add" @if ($errors->any()) open @endif style="margin-bottom:var(--pg-gap)">
            <summary class="pg-card-head" style="cursor:pointer;margin:0"><h2>Raise a change</h2><small>It starts as a draft; assess the impact before sending it for approval</small></summary>
            <form wire:submit="add" class="pg-form" style="margin-top:12px">
                <div class="pg-form-row">
                    <div class="pg-field">
                        <label for="c-title" class="pg-label">What should change</label>
                        <input id="c-title" type="text" wire:model="title" maxlength="255" class="pg-input" placeholder="Add a second loading bay to the scope" required>
                        @error('title')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="c-kind" class="pg-label">Mainly a change to</label>
                        <select id="c-kind" wire:model="kind" class="pg-select">
                            @foreach (App\Models\ProjectChange::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div><button type="submit" class="pg-btn pg-btn--primary">Start the draft</button></div>
            </form>
        </details>
    @endif

    <section class="pg-table-wrap" aria-labelledby="cl-h">
        <div class="pg-filters" style="padding:12px 16px 4px;margin:0">
            <h2 id="cl-h" style="font-size:14px;font-weight:600;margin:0 auto 0 0">Change log</h2>
            <label for="cl-show" class="pg-sr">Which changes</label>
            <select id="cl-show" wire:model.live="show" class="pg-select" style="width:auto">
                <option value="all">All</option>
                <option value="open">Draft or waiting</option>
                <option value="accepted">Approved or implemented</option>
                <option value="closed">Rejected or withdrawn</option>
            </select>
        </div>
        @if ($this->changes->isEmpty())
            <p class="pg-empty" style="padding:8px 16px 16px">{{ $this->all->isEmpty() ? 'The log lists every change with its effect on time and cost, who asked and who decided.' : 'No change matches.' }}</p>
        @else
            <table class="pg-table pg-table--changes">
                <thead><tr><th scope="col">Change</th><th scope="col">Asked by</th><th scope="col" class="r">Schedule</th><th scope="col" class="r">Cost</th><th scope="col">Approver</th><th scope="col">Status</th></tr></thead>
                <tbody>
                    @foreach ($this->changes as $c)
                        <tr wire:key="change-{{ $c->id }}">
                            <td class="pg-name"><span class="pg-id">{{ $c->ref() }}</span> <a href="{{ route('projects.changes.show', [$project, $c]) }}" @if (in_array($c->status, ['rejected', 'withdrawn'], true)) style="color:var(--pg-muted)" @endif>{{ $c->title }}</a><span class="pg-sub">{{ App\Models\ProjectChange::KINDS[$c->kind] ?? 'Other' }}</span></td>
                            <td data-label="Asked by">{{ $c->requester?->name ?? 'Someone' }}</td>
                            <td data-label="Schedule" class="r {{ $c->schedule_days === null ? 'pg-muted' : '' }}">{{ $daysText($c->schedule_days) ?? 'Not assessed' }}</td>
                            <td data-label="Cost" class="r {{ $c->cost_cents === null ? 'pg-muted' : '' }}">{{ $c->cost_cents === null ? 'Not assessed' : App\Support\Money::rand($c->cost_cents, true) }}</td>
                            <td data-label="Approver" class="{{ $c->approver ? '' : 'pg-muted' }}">{{ $c->approver?->name ?? 'Not sent' }}</td>
                            <td data-label="Status"><span class="pg-pill {{ $statusPill[$c->status] ?? '' }}">{{ App\Models\ProjectChange::STATUSES[$c->status] ?? $c->status }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
