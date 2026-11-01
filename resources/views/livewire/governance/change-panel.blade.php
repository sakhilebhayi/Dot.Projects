<div class="pg-grid">
    @php
        $draft = $change->status === 'draft';
        $editable = $canEdit && $draft;
        $statusPill = ['draft' => '', 'submitted' => 'pg-pill--warning', 'approved' => 'pg-pill--positive', 'implemented' => 'pg-pill--positive', 'rejected' => 'pg-pill--negative', 'withdrawn' => ''];
        $daysText = fn (?int $d) => $d === null ? 'Not assessed' : ($d === 0 ? 'No change to the dates' : ($d > 0 ? $d.' '.Str::plural('day', $d).' later' : abs($d).' '.Str::plural('day', abs($d)).' earlier'));
    @endphp

    <section class="pg-card pg-s8" aria-labelledby="change-h">
        <h2 id="change-h" class="pg-sr">Change request</h2>
        <form wire:submit="save" class="pg-form">
            <fieldset @disabled(! $editable) style="border:0;margin:0;padding:0;display:grid;gap:14px;min-width:0">
                <div class="pg-form-row">
                    <div class="pg-field">
                        <label for="ch-title" class="pg-label"><span class="pg-id">{{ $change->ref() }}</span> What should change</label>
                        <input id="ch-title" type="text" wire:model="title" maxlength="255" class="pg-input" style="font-size:16px;font-weight:500" required>
                        @error('title')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="ch-kind" class="pg-label">Mainly a change to</label>
                        <select id="ch-kind" wire:model="kind" class="pg-select">
                            @foreach (App\Models\ProjectChange::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="pg-field">
                    <label for="ch-reason" class="pg-label">Why it is needed</label>
                    <textarea id="ch-reason" wire:model="reason" rows="3" maxlength="5000" class="pg-textarea" placeholder="What happens if we do not make this change."></textarea>
                    @error('reason')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-field">
                    <label for="ch-desc" class="pg-label">What exactly changes</label>
                    <textarea id="ch-desc" wire:model="description" rows="3" maxlength="5000" class="pg-textarea" placeholder="The work, deliverables or dates as they would be after the change."></textarea>
                    @error('description')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <fieldset class="pg-fieldset">
                    <legend>Impact assessment</legend>
                    <div style="display:grid;gap:14px">
                        <div class="pg-form-row">
                            <div class="pg-field">
                                <label for="ch-days" class="pg-label">Schedule, in days</label>
                                <input id="ch-days" type="number" step="1" wire:model="scheduleDays" class="pg-input" placeholder="12 later, or -5 earlier" aria-describedby="ch-days-help">
                                <p id="ch-days-help" class="pg-help" style="margin:4px 0 0">How much later (or, negative, earlier) the project finishes.</p>
                                @error('schedule_days')<p class="pg-error">{{ $message }}</p>@enderror
                            </div>
                            <div class="pg-field">
                                <label for="ch-cost" class="pg-label">Cost, in rand</label>
                                <input id="ch-cost" type="text" inputmode="decimal" wire:model="cost" class="pg-input" placeholder="45 000, or -12 500 saved" aria-describedby="ch-cost-help">
                                <p id="ch-cost-help" class="pg-help" style="margin:4px 0 0">What it adds to (or, negative, saves from) the cost.</p>
                                @error('cost')<p class="pg-error">{{ $message }}</p>@enderror
                                @error('cost_cents')<p class="pg-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <div class="pg-field">
                            <label for="ch-scope" class="pg-label">Scope and quality</label>
                            <textarea id="ch-scope" wire:model="scopeImpact" rows="2" maxlength="5000" class="pg-textarea"></textarea>
                        </div>
                        <div class="pg-field">
                            <label for="ch-res" class="pg-label">People and resources</label>
                            <textarea id="ch-res" wire:model="resourceImpact" rows="2" maxlength="5000" class="pg-textarea"></textarea>
                        </div>
                        <div class="pg-field">
                            <label for="ch-risk" class="pg-label">Risks it adds or removes</label>
                            <textarea id="ch-risk" wire:model="riskImpact" rows="2" maxlength="5000" class="pg-textarea"></textarea>
                        </div>
                    </div>
                </fieldset>
            </fieldset>
            @error('change')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
            @if ($editable)
                <div class="pg-actions" style="justify-content:flex-end;align-items:center;border-top:1px solid var(--pg-rule);padding-top:14px">
                    @if (session('saved'))<span class="pg-help" role="status" style="margin:0">{{ session('saved') }}</span>@endif
                    <button type="submit" class="pg-btn pg-btn--secondary" wire:loading.attr="disabled" wire:target="save">Save the draft</button>
                </div>
            @elseif (! $draft)
                <p class="pg-help" style="margin:0">Sent for approval on {{ $change->submitted_at?->format('j M Y') }}, so what was asked stays as it was. To ask for something different, withdraw it and raise a new change.</p>
            @else
                <p class="pg-help" style="margin:0">{{ $project->isArchived() ? 'This project is archived, so its changes are read-only.' : 'You have read-only access to this business.' }}</p>
            @endif
        </form>
    </section>

    <aside class="pg-s4" style="display:grid;gap:var(--pg-gap);align-content:start">
        <section class="pg-card" aria-labelledby="appr-h">
            <div class="pg-card-head"><h2 id="appr-h">Approval</h2><span class="pg-pill {{ $statusPill[$change->status] ?? '' }}">{{ App\Models\ProjectChange::STATUSES[$change->status] ?? $change->status }}</span></div>
            <dl class="pg-kv" style="margin-bottom:12px">
                <dt>Asked by</dt><dd>{{ $change->requester?->name ?? 'Someone' }}, {{ $change->created_at->format('j M') }}</dd>
                <dt>Schedule</dt><dd>{{ $daysText($change->schedule_days) }}</dd>
                <dt>Cost</dt><dd>{{ $change->cost_cents === null ? 'Not assessed' : App\Support\Money::rand($change->cost_cents, true) }}</dd>
            </dl>

            @if ($draft && $canEdit)
                <form wire:submit="submit" class="pg-form" style="gap:8px;border-top:1px solid var(--pg-rule);padding-top:12px">
                    <label for="ch-appr" class="pg-label" style="margin:0">Who approves it</label>
                    <select id="ch-appr" wire:model="approver" class="pg-select">
                        <option value="">Choose</option>
                        @foreach ($approvers as $person)<option value="{{ $person->id }}">{{ $person->name }}{{ $person->id === $project->owner_id ? ', project owner' : '' }}{{ $person->id === auth()->id() ? ', you' : '' }}</option>@endforeach
                    </select>
                    @error('approver')<p class="pg-error">{{ $message }}</p>@enderror
                    @error('reason')<p class="pg-error">{{ $message }}</p>@enderror
                    <p class="pg-help" style="margin:0">Sending saves the draft. After that it cannot be edited, only approved, rejected or withdrawn.</p>
                    <div class="pg-actions"><button type="submit" class="pg-btn pg-btn--primary">Send for approval</button><button type="button" class="pg-btn pg-btn--ghost" wire:click="withdraw">Withdraw</button></div>
                </form>
            @elseif ($change->status === 'submitted')
                <p style="margin:0 0 10px;border-top:1px solid var(--pg-rule);padding-top:12px">Waiting on <b>{{ $change->approver?->name ?? 'the approver' }}</b> since {{ $change->submitted_at?->format('j M') }}.</p>
                @if ($canDecide)
                    <form wire:submit="approve" class="pg-form" style="gap:8px">
                        <label for="ch-note" class="pg-label" style="margin:0">Your note</label>
                        <textarea id="ch-note" wire:model="note" rows="3" maxlength="5000" class="pg-textarea" placeholder="Conditions, or why you reject it. Required to reject."></textarea>
                        @error('decision')<p class="pg-error">{{ $message }}</p>@enderror
                        @if ($change->requested_by === auth()->id())<p class="pg-help" style="margin:0">You asked for this change, so approving it is recorded as self-approved.</p>@endif
                        <div class="pg-actions"><button type="submit" class="pg-btn pg-btn--primary">Approve</button><button type="button" class="pg-btn pg-btn--danger" wire:click="reject">Reject</button></div>
                    </form>
                @elseif ($canEdit)
                    <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="withdraw">Withdraw the request</button>
                @endif
            @elseif ($change->decided_at)
                <div style="border-top:1px solid var(--pg-rule);padding-top:12px">
                    <p style="margin:0 0 4px"><b>{{ $change->status === 'rejected' ? 'Rejected' : 'Approved' }}</b> by {{ $change->decider?->name ?? 'someone' }} on {{ $change->decided_at->format('j M Y') }}{{ $change->self_approved ? ', who also asked for it' : '' }}.</p>
                    @if ($change->decision_note)<p class="pg-help" style="margin:0 0 8px;white-space:pre-line">{{ $change->decision_note }}</p>@endif
                    @if ($change->decision)<p class="pg-help" style="margin:0">Recorded as <a href="{{ route('projects.decisions.show', [$project, $change->decision]) }}"><span class="pg-id">{{ $change->decision->ref() }}</span></a> in the decision register.</p>@endif
                </div>
            @else
                <p class="pg-empty" style="border-top:1px solid var(--pg-rule);padding-top:12px">Withdrawn.</p>
            @endif
            @error('change')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
        </section>

        @if ($change->accepted())
            <section class="pg-card" aria-labelledby="plan-h">
                <div class="pg-card-head"><h2 id="plan-h">Into the plan</h2></div>
                @if ($change->baseline)
                    <p style="margin:0 0 8px">The plan was re-baselined after this change: <a href="{{ route('projects.schedule', [$project, 'baseline' => $change->baseline_id]) }}">baseline {{ $change->baseline->number }}</a>.</p>
                @elseif ($canEdit)
                    <p class="pg-help" style="margin:0 0 8px">Once the tasks and dates reflect this change, take a new baseline so later slippage is measured from the agreed plan.</p>
                    <button type="button" class="pg-btn pg-btn--secondary pg-btn--sm" wire:click="rebaseline" style="margin-bottom:10px">Re-baseline now</button>
                @endif
                @if ($change->status === 'implemented')
                    <p class="pg-help" style="margin:0">Implemented {{ $change->implemented_at?->format('j M Y') }}.</p>
                @elseif ($canEdit)
                    <div><button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="implement">Mark implemented</button></div>
                @endif
            </section>
        @endif

        <section class="pg-card" aria-labelledby="hist-h">
            <div class="pg-card-head"><h2 id="hist-h">History</h2></div>
            @foreach ($history as $line)
                <div class="pg-act"><time datetime="{{ $line['at']->toIso8601String() }}">{{ $line['at']->format('j M, H:i') }}</time><span>@if ($line['who'])<b style="font-weight:500">{{ $line['who'] }}</b> @endif{{ $line['text'] }}</span></div>
            @endforeach
        </section>
    </aside>
</div>
