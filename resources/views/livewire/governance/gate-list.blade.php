<div>
    @php
        $gates = $this->gates;
        $next = $gates->first(fn ($g) => $g->open());
        $statusPill = ['pending' => '', 'passed' => 'pg-pill--positive', 'conditional' => 'pg-pill--warning', 'held' => 'pg-pill--negative'];
    @endphp

    <div class="pg-titlerow" style="margin-top:0">
        <p class="pg-finding" style="margin:0">
            @if ($gates->isEmpty())
                No stage gates. A gate is a point the project only passes once its checklist is met and someone with authority says go.
            @elseif ($next === null)
                Every gate is passed.
            @else
                Next gate: <b>{{ $next->ref() }} {{ $next->title }}</b>, {{ $next->checks_done_count }} of {{ $next->checks_count }} {{ Str::plural('check', $next->checks_count) }} met{{ $next->due_date ? ', due '.$next->due_date->format('j M') : '' }}{{ $next->overdue() ? ' (past due)' : '' }}{{ $next->status === 'held' ? ', currently held' : '' }}.
            @endif
        </p>
        @if ($canEdit)
            <div class="pg-actions"><a href="#add-gate" class="pg-btn pg-btn--primary" x-data x-on:click.prevent="document.getElementById('add-gate').open = true; $nextTick(() => document.getElementById('g-title').focus())">Add a gate</a></div>
        @endif
    </div>

    @if ($canEdit)
        <details id="add-gate" class="pg-card pg-add" @if ($errors->any()) open @endif style="margin-bottom:var(--pg-gap)">
            <summary class="pg-card-head" style="cursor:pointer;margin:0"><h2>Add a gate</h2><small>Start from a standard checklist, then make it yours</small></summary>
            <form wire:submit="add" class="pg-form" style="margin-top:12px">
                <div class="pg-form-row">
                    <div class="pg-field">
                        <label for="g-template" class="pg-label">Checklist to start from</label>
                        <select id="g-template" wire:model.live="template" class="pg-select">
                            @foreach (App\Services\Gates::TEMPLATES as $key => $t)<option value="{{ $key }}">{{ $t['title'] }}, {{ count($t['checks']) }} checks</option>@endforeach
                            <option value="blank">Empty checklist</option>
                        </select>
                    </div>
                    <div class="pg-field">
                        <label for="g-title" class="pg-label">Gate</label>
                        <input id="g-title" type="text" wire:model="title" maxlength="255" class="pg-input" required>
                        @error('title')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="pg-form-row pg-form-row--3">
                    <div class="pg-field">
                        <label for="g-approver" class="pg-label">Who decides</label>
                        <select id="g-approver" wire:model="approver" class="pg-select">
                            <option value="">Not set</option>
                            @foreach ($approvers as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                        </select>
                        @error('approver_id')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="g-ms" class="pg-label">At milestone</label>
                        <select id="g-ms" wire:model="milestone" class="pg-select">
                            <option value="">None</option>
                            @foreach ($milestones as $m)<option value="{{ $m->id }}">{{ $m->title }}</option>@endforeach
                        </select>
                        @error('milestone_id')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="g-due" class="pg-label">Due</label>
                        <input id="g-due" type="date" wire:model="due" class="pg-input">
                        @error('due_date')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div><button type="submit" class="pg-btn pg-btn--primary">Add the gate</button></div>
            </form>
        </details>
    @endif

    @if ($gates->isNotEmpty())
        <ol class="pg-gates" aria-label="Stage gates in order">
            @foreach ($gates as $g)
                <li class="pg-card pg-gate is-{{ $g->status }}" wire:key="gate-{{ $g->id }}">
                    <div class="pg-gate-head">
                        <span class="pg-gate-no" aria-hidden="true">{{ $g->ref() }}</span>
                        <div style="min-width:0">
                            <a href="{{ route('projects.gates.show', [$project, $g]) }}" class="pg-gate-title">{{ $g->title }}</a>
                            <div class="pg-sub">{{ $g->approver ? $g->approver->name.' decides' : 'Nobody named to decide' }}@if ($g->milestone), at {{ $g->milestone->title }}@endif</div>
                        </div>
                        <span class="pg-pill {{ $statusPill[$g->status] ?? '' }}">{{ App\Models\ProjectGate::STATUSES[$g->status] ?? $g->status }}</span>
                    </div>
                    <div class="pg-gate-meter" role="img" aria-label="{{ $g->checks_done_count }} of {{ $g->checks_count }} checks met">
                        @for ($i = 0; $i < $g->checks_count; $i++)<i class="{{ $i < $g->checks_done_count ? 'on' : '' }}"></i>@endfor
                    </div>
                    <p class="pg-help" style="margin:6px 0 0">{{ $g->checks_done_count }} of {{ $g->checks_count }} {{ Str::plural('check', $g->checks_count) }} met.
                        @if ($g->decided_at && $g->status !== 'pending') {{ App\Models\ProjectGate::STATUSES[$g->status] }} {{ $g->decided_at->format('j M Y') }}.@elseif ($g->due_date)<span class="{{ $g->overdue() ? 'pg-neg' : '' }}">{{ $g->overdue() ? 'Was due' : 'Due' }} {{ $g->due_date->format('j M Y') }}.</span>@endif
                    </p>
                </li>
            @endforeach
        </ol>
    @endif
</div>
