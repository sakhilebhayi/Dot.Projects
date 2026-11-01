<div class="pg-grid">
    @php
        $sev = ['low' => '', 'medium' => 'pg-pill--warning', 'high' => 'pg-pill--negative', 'critical' => 'pg-pill--negative pg-pill--solid'];
        $open = $issue->isOpen();
    @endphp

    <section class="pg-card pg-s8" aria-labelledby="issue-h">
        <h2 id="issue-h" class="pg-sr">Issue details</h2>
        <form wire:submit="save" class="pg-form">
            <fieldset @disabled(! $canEdit) style="border:0;margin:0;padding:0;display:grid;gap:14px;min-width:0">
                <div class="pg-field">
                    <label for="is-title" class="pg-label"><span class="pg-id">{{ $issue->ref() }}</span> What has happened</label>
                    <input id="is-title" type="text" wire:model="title" maxlength="255" class="pg-input" style="font-size:16px;font-weight:500" required>
                    @error('title')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-field">
                    <label for="is-desc" class="pg-label">What it affects</label>
                    <textarea id="is-desc" wire:model="description" rows="3" maxlength="5000" class="pg-textarea" placeholder="Which work, dates, money or people it holds up."></textarea>
                    @error('description')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-form-row pg-form-row--3">
                    <div class="pg-field">
                        <label for="is-sev" class="pg-label">Severity</label>
                        <select id="is-sev" wire:model="severity" class="pg-select">
                            @foreach (App\Models\ProjectIssue::SEVERITIES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="pg-field">
                        <label for="is-pri" class="pg-label">Priority</label>
                        <select id="is-pri" wire:model="priority" class="pg-select">
                            @foreach (App\Services\ProjectWork::PRIORITIES as $value)<option value="{{ $value }}">{{ ucfirst($value) }}</option>@endforeach
                        </select>
                    </div>
                    <div class="pg-field">
                        <label for="is-cat" class="pg-label">Category</label>
                        <select id="is-cat" wire:model="category" class="pg-select">
                            @foreach (App\Services\Governance::CATEGORIES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="pg-form-row pg-form-row--3">
                    <div class="pg-field">
                        <label for="is-owner" class="pg-label">Owner</label>
                        <select id="is-owner" wire:model="owner" class="pg-select">
                            <option value="">Nobody yet</option>
                            @foreach ($people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                        </select>
                        @error('owner_id')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="is-target" class="pg-label">Resolve by</label>
                        <input id="is-target" type="date" wire:model="target" class="pg-input">
                        @error('target_date')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="is-status" class="pg-label">Status</label>
                        <select id="is-status" wire:model.live="status" class="pg-select">
                            @foreach (App\Models\ProjectIssue::STATUSES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                        @error('status')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="pg-field">
                    <label for="is-root" class="pg-label">Root cause</label>
                    <textarea id="is-root" wire:model="rootCause" rows="3" maxlength="5000" class="pg-textarea" placeholder="Why it happened, not only what happened."></textarea>
                    @error('root_cause')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-field">
                    <label for="is-action" class="pg-label">Corrective action</label>
                    <textarea id="is-action" wire:model="correctiveAction" rows="3" maxlength="5000" class="pg-textarea" placeholder="What is being done to fix it and stop it happening again."></textarea>
                    @error('corrective_action')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-field">
                    <label for="is-res" class="pg-label">Resolution @if (! in_array($status, ['resolved', 'closed'], true))<span class="pg-muted" style="font-weight:400">, needed to mark it resolved</span>@endif</label>
                    <textarea id="is-res" wire:model="resolution" rows="2" maxlength="5000" class="pg-textarea" placeholder="How it was resolved, and when."></textarea>
                    @error('resolution')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
            </fieldset>
            @if ($canEdit)
                <div class="pg-actions" style="justify-content:flex-end;align-items:center;border-top:1px solid var(--pg-rule);padding-top:14px">
                    @if (session('saved'))<span class="pg-help" role="status" style="margin:0">{{ session('saved') }}</span>@endif
                    <button type="submit" class="pg-btn pg-btn--primary" wire:loading.attr="disabled" wire:target="save">Save changes</button>
                </div>
            @else
                <p class="pg-help" style="margin:0">{{ $project->isArchived() ? 'This project is archived, so its log is read-only.' : 'You have read-only access to this business.' }}</p>
            @endif
        </form>
    </section>

    <aside class="pg-s4" style="display:grid;gap:var(--pg-gap);align-content:start">
        <section class="pg-card" aria-labelledby="state-h">
            <div class="pg-card-head"><h2 id="state-h">Where it stands</h2></div>
            <dl class="pg-kv">
                <dt>Severity</dt><dd><span class="pg-pill {{ $sev[$issue->severity] ?? '' }}">{{ App\Models\ProjectIssue::SEVERITIES[$issue->severity] ?? $issue->severity }}</span></dd>
                <dt>Status</dt><dd>{{ App\Models\ProjectIssue::STATUSES[$issue->status] ?? $issue->status }}</dd>
                <dt>Resolve by</dt><dd class="{{ $issue->late() ? 'pg-neg' : '' }}">{{ $issue->target_date ? $issue->target_date->format('j M Y').($issue->late() ? ', '.(int) $issue->target_date->diffInDays(today()).' days late' : '') : 'No date' }}</dd>
                @if ($issue->resolved_at)<dt>Resolved</dt><dd>{{ $issue->resolved_at->format('j M Y') }}</dd>@endif
                @if ($issue->risk)<dt>Came from</dt><dd><a href="{{ route('projects.risks.show', [$project, $issue->risk]) }}"><span class="pg-id">{{ $issue->risk->ref() }}</span> {{ Str::limit($issue->risk->title, 40) }}</a></dd>@endif
            </dl>
        </section>

        <section class="pg-card" aria-labelledby="esc-h">
            <div class="pg-card-head"><h2 id="esc-h">Escalation</h2></div>
            @if ($issue->escalated_at)
                <p style="margin:0 0 4px"><b>Escalated to {{ $project->owner?->name ?? 'the project owner' }}</b> by {{ $issue->escalator?->name ?? 'someone' }} on {{ $issue->escalated_at->format('j M Y') }}.</p>
                <p class="pg-help" style="margin:0 0 10px;white-space:pre-line">{{ $issue->escalation_note }}</p>
                @if ($canEdit)<button type="button" class="pg-btn pg-btn--secondary pg-btn--sm" wire:click="withdraw">Close the escalation</button>@endif
            @elseif ($canEdit && $open)
                <form wire:submit="escalate" class="pg-form" style="gap:8px">
                    <label for="is-esc" class="pg-label" style="margin:0">What do you need from {{ $project->owner?->name ?? 'the project owner' }}?</label>
                    <textarea id="is-esc" wire:model="escalationNote" rows="2" maxlength="500" class="pg-textarea" placeholder="Approval to hire a second crane for two weeks."></textarea>
                    @error('escalation')<p class="pg-error">{{ $message }}</p>@enderror
                    <div><button type="submit" class="pg-btn pg-btn--secondary pg-btn--sm">Escalate</button></div>
                </form>
            @else
                <p class="pg-empty">Not escalated.</p>
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
