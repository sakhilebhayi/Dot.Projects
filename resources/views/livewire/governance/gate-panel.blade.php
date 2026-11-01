<div class="pg-grid">
    @php
        $open = $gate->open();
        $editable = $canEdit && $open;
        $done = $checks->whereNotNull('done_at')->count();
        $unmet = $checks->count() - $done;
        $statusPill = ['pending' => '', 'passed' => 'pg-pill--positive', 'conditional' => 'pg-pill--warning', 'held' => 'pg-pill--negative'];
    @endphp

    <div class="pg-s8" style="display:grid;gap:var(--pg-gap);align-content:start">
        <section class="pg-card" aria-labelledby="gate-h">
            <h2 id="gate-h" class="pg-sr">Gate details</h2>
            <form wire:submit="save" class="pg-form">
                <fieldset @disabled(! $editable) style="border:0;margin:0;padding:0;display:grid;gap:14px;min-width:0">
                    <div class="pg-field">
                        <label for="gt-title" class="pg-label"><span class="pg-id">{{ $gate->ref() }}</span> Gate</label>
                        <input id="gt-title" type="text" wire:model="title" maxlength="255" class="pg-input" style="font-size:16px;font-weight:500" required>
                        @error('title')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="gt-purpose" class="pg-label">What passing it allows</label>
                        <textarea id="gt-purpose" wire:model="purpose" rows="2" maxlength="5000" class="pg-textarea" placeholder="Committing the main contractor and the long-lead orders."></textarea>
                    </div>
                    <div class="pg-form-row pg-form-row--3">
                        <div class="pg-field">
                            <label for="gt-appr" class="pg-label">Who decides</label>
                            <select id="gt-appr" wire:model="approver" class="pg-select">
                                <option value="">Not set</option>
                                @foreach ($approvers as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                            </select>
                            @error('approver_id')<p class="pg-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="pg-field">
                            <label for="gt-ms" class="pg-label">At milestone</label>
                            <select id="gt-ms" wire:model="milestone" class="pg-select">
                                <option value="">None</option>
                                @foreach ($milestones as $m)<option value="{{ $m->id }}">{{ $m->title }}</option>@endforeach
                            </select>
                        </div>
                        <div class="pg-field">
                            <label for="gt-due" class="pg-label">Due</label>
                            <input id="gt-due" type="date" wire:model="due" class="pg-input">
                            @if ($gate->overdue())<p class="pg-help pg-neg" style="margin:4px 0 0">Past due since {{ $gate->due_date->format('j M') }}.</p>@endif
                        </div>
                    </div>
                </fieldset>
                @if ($editable)
                    <div class="pg-actions" style="justify-content:flex-end;align-items:center;border-top:1px solid var(--pg-rule);padding-top:14px">
                        @if (session('saved'))<span class="pg-help" role="status" style="margin:0">{{ session('saved') }}</span>@endif
                        <button type="submit" class="pg-btn pg-btn--secondary" wire:loading.attr="disabled" wire:target="save">Save</button>
                    </div>
                @endif
            </form>
        </section>

        <section class="pg-card" aria-labelledby="chk-h">
            <div class="pg-card-head"><h2 id="chk-h">Governance checklist</h2><small>{{ $done }} of {{ $checks->count() }} met</small></div>
            @if ($checks->isEmpty())
                <p class="pg-empty">No checks yet.{{ $editable ? ' Add what must be true before this gate can pass.' : '' }}</p>
            @else
                <ul class="pg-checks">
                    @foreach ($checks as $c)
                        <li class="{{ $c->done_at ? 'is-done' : '' }}" wire:key="chk-{{ $c->id }}">
                            <input type="checkbox" class="pg-check" id="chk-{{ $c->id }}" @checked($c->done_at) @disabled(! $editable) wire:click="toggleCheck({{ $c->id }})">
                            <label for="chk-{{ $c->id }}">{{ $c->body }}@if ($c->done_at)<span class="pg-sub" style="display:block">Met, {{ $c->doer?->name ?? 'someone' }}, {{ $c->done_at->format('j M') }}</span>@endif</label>
                            @if ($editable)<button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="removeCheck({{ $c->id }})" aria-label="Remove the check {{ $c->body }}">Remove</button>@else<span></span>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($editable)
                <form wire:submit="addCheck" style="display:flex;gap:8px;margin-top:12px">
                    <label for="chk-new" class="pg-sr">New check</label>
                    <input id="chk-new" type="text" wire:model="newCheck" maxlength="255" class="pg-input" placeholder="Something that must be true before this gate passes">
                    <button type="submit" class="pg-btn pg-btn--secondary">Add</button>
                </form>
                @error('check')<p class="pg-error">{{ $message }}</p>@enderror
            @endif
            @error('gate')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
        </section>
    </div>

    <aside class="pg-s4" style="display:grid;gap:var(--pg-gap);align-content:start">
        <section class="pg-card" aria-labelledby="gd-h">
            <div class="pg-card-head"><h2 id="gd-h">Decision</h2><span class="pg-pill {{ $statusPill[$gate->status] ?? '' }}">{{ App\Models\ProjectGate::STATUSES[$gate->status] ?? $gate->status }}</span></div>
            @if ($gate->status === 'pending')
                @if ($canDecide)
                    <form wire:submit="decide" class="pg-form" style="gap:10px">
                        <fieldset style="border:0;margin:0;padding:0;display:grid;gap:6px">
                            <legend class="pg-label" style="margin-bottom:4px">Your decision</legend>
                            <label class="pg-radio"><input type="radio" wire:model.live="outcome" value="passed" @disabled($unmet > 0)> Pass{{ $unmet > 0 ? ' (needs every check met)' : '' }}</label>
                            <label class="pg-radio"><input type="radio" wire:model.live="outcome" value="conditional"> Pass with conditions</label>
                            <label class="pg-radio"><input type="radio" wire:model.live="outcome" value="held"> Hold</label>
                        </fieldset>
                        <label for="gd-note" class="pg-label" style="margin:0">{{ $outcome === 'held' ? 'Why it is held, and what would let it pass' : ($outcome === 'conditional' ? 'The conditions' : 'Note (optional)') }}</label>
                        <textarea id="gd-note" wire:model="note" rows="3" maxlength="5000" class="pg-textarea"></textarea>
                        @error('decision')<p class="pg-error">{{ $message }}</p>@enderror
                        <div><button type="submit" class="pg-btn pg-btn--primary">Record the decision</button></div>
                    </form>
                @else
                    <p class="pg-empty">{{ $gate->approver ? 'Waiting on '.$gate->approver->name.'.' : 'Name who decides this gate.' }} {{ $unmet > 0 ? $unmet.' '.Str::plural('check', $unmet).' still to meet.' : 'Every check is met.' }}</p>
                @endif
            @else
                <p style="margin:0 0 4px"><b>{{ App\Models\ProjectGate::STATUSES[$gate->status] }}</b> by {{ $gate->decider?->name ?? 'someone' }}{{ $gate->decided_at ? ' on '.$gate->decided_at->format('j M Y') : '' }}.</p>
                @if ($gate->decision_note)<p class="pg-help" style="margin:0 0 8px;white-space:pre-line">{{ $gate->decision_note }}</p>@endif
                @if ($gate->decision)<p class="pg-help" style="margin:0">Recorded as <a href="{{ route('projects.decisions.show', [$project, $gate->decision]) }}"><span class="pg-id">{{ $gate->decision->ref() }}</span></a> in the decision register.</p>@endif
                @if ($gate->status === 'held' && $canEdit)<button type="button" class="pg-btn pg-btn--secondary pg-btn--sm" style="margin-top:10px" wire:click="reopen">Put it forward again</button>@endif
            @endif
        </section>

        <section class="pg-card" aria-labelledby="hist-h">
            <div class="pg-card-head"><h2 id="hist-h">History</h2></div>
            @foreach ($history as $line)
                <div class="pg-act"><time datetime="{{ $line['at']->toIso8601String() }}">{{ $line['at']->format('j M, H:i') }}</time><span>@if ($line['who'])<b style="font-weight:500">{{ $line['who'] }}</b> @endif{{ $line['text'] }}</span></div>
            @endforeach
        </section>
    </aside>
</div>
