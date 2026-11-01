<div class="pg-grid">
    @php
        $p = (int) $probability;
        $i = (int) $impact;
        $rp = (int) $residualProbability;
        $ri = (int) $residualImpact;
        $score = $p && $i ? $p * $i : null;
        $rscore = $rp && $ri ? $rp * $ri : null;
        $pill = ['low' => '', 'medium' => 'pg-pill--warning', 'high' => 'pg-pill--negative', 'critical' => 'pg-pill--negative pg-pill--solid'];
        $live = $risk->isLive();
    @endphp

    <section class="pg-card pg-s8" aria-labelledby="risk-h">
        <h2 id="risk-h" class="pg-sr">Risk details</h2>
        <form wire:submit="save" class="pg-form">
            <fieldset @disabled(! $canEdit) style="border:0;margin:0;padding:0;display:grid;gap:14px;min-width:0">
                <div class="pg-field">
                    <label for="rk-title" class="pg-label"><span class="pg-id">{{ $risk->ref() }}</span> What could happen</label>
                    <input id="rk-title" type="text" wire:model="title" maxlength="255" class="pg-input" style="font-size:16px;font-weight:500" required>
                    @error('title')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-field">
                    <label for="rk-desc" class="pg-label">Cause and effect</label>
                    <textarea id="rk-desc" wire:model="description" rows="3" maxlength="5000" class="pg-textarea" placeholder="Because of what, and what it would do to the date, the money or the people."></textarea>
                    @error('description')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-form-row pg-form-row--3">
                    <div class="pg-field">
                        <label for="rk-owner" class="pg-label">Owner</label>
                        <select id="rk-owner" wire:model="owner" class="pg-select">
                            <option value="">Nobody yet</option>
                            @foreach ($people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                        </select>
                        @error('owner_id')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="rk-cat" class="pg-label">Category</label>
                        <select id="rk-cat" wire:model="category" class="pg-select">
                            @foreach (App\Services\Governance::CATEGORIES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="pg-field">
                        <label for="rk-status" class="pg-label">Status</label>
                        <select id="rk-status" wire:model="status" class="pg-select">
                            @foreach (App\Models\ProjectRisk::STATUSES as $value => $label)
                                @if ($value !== 'occurred' || $risk->status === 'occurred')<option value="{{ $value }}">{{ $label }}</option>@endif
                            @endforeach
                        </select>
                        @error('status')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <fieldset class="pg-fieldset">
                    <legend>Before mitigation</legend>
                    <div class="pg-form-row">
                        <div class="pg-field">
                            <label for="rk-p" class="pg-label">How likely</label>
                            <select id="rk-p" wire:model.live="probability" class="pg-select">
                                @foreach ($matrix->probability as $k => $label)<option value="{{ $k + 1 }}">{{ $label }}</option>@endforeach
                            </select>
                            @error('probability')<p class="pg-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="pg-field">
                            <label for="rk-i" class="pg-label">Impact</label>
                            <select id="rk-i" wire:model.live="impact" class="pg-select">
                                @foreach ($matrix->impact as $k => $label)<option value="{{ $k + 1 }}">{{ $label }}</option>@endforeach
                            </select>
                            @error('impact')<p class="pg-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </fieldset>

                <div class="pg-field">
                    <label for="rk-mit" class="pg-label">Mitigation: what we do now to make it less likely or less harmful</label>
                    <textarea id="rk-mit" wire:model="mitigation" rows="3" maxlength="5000" class="pg-textarea"></textarea>
                    @error('mitigation')<p class="pg-error">{{ $message }}</p>@enderror
                </div>

                <fieldset class="pg-fieldset">
                    <legend>After mitigation</legend>
                    <div class="pg-form-row">
                        <div class="pg-field">
                            <label for="rk-rp" class="pg-label">How likely</label>
                            <select id="rk-rp" wire:model.live="residualProbability" class="pg-select">
                                <option value="">Not rated</option>
                                @foreach ($matrix->probability as $k => $label)<option value="{{ $k + 1 }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>
                        <div class="pg-field">
                            <label for="rk-ri" class="pg-label">Impact</label>
                            <select id="rk-ri" wire:model.live="residualImpact" class="pg-select">
                                <option value="">Not rated</option>
                                @foreach ($matrix->impact as $k => $label)<option value="{{ $k + 1 }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    @error('residual')<p class="pg-error">{{ $message }}</p>@enderror
                    @error('residual_probability')<p class="pg-error">{{ $message }}</p>@enderror
                    @error('residual_impact')<p class="pg-error">{{ $message }}</p>@enderror
                </fieldset>

                <div class="pg-field">
                    <label for="rk-con" class="pg-label">Contingency: what we do if it happens anyway</label>
                    <textarea id="rk-con" wire:model="contingency" rows="3" maxlength="5000" class="pg-textarea"></textarea>
                    @error('contingency')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-field" style="max-width:320px">
                    <label for="rk-review" class="pg-label">Next review</label>
                    <input id="rk-review" type="date" wire:model="review" class="pg-input" @if ($risk->reviewOverdue()) aria-describedby="rk-review-late" @endif>
                    @if ($risk->reviewOverdue())<p id="rk-review-late" class="pg-help pg-neg" style="margin:4px 0 0">Overdue since {{ $risk->review_date->format('j M') }}. Review it, then set the next date.</p>@endif
                    @error('review_date')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
            </fieldset>
            @if ($canEdit)
                <div class="pg-actions" style="justify-content:flex-end;align-items:center;border-top:1px solid var(--pg-rule);padding-top:14px">
                    @if (session('saved'))<span class="pg-help" role="status" style="margin:0">{{ session('saved') }}</span>@endif
                    <button type="submit" class="pg-btn pg-btn--primary" wire:loading.attr="disabled" wire:target="save">Save changes</button>
                </div>
            @else
                <p class="pg-help" style="margin:0">{{ $project->isArchived() ? 'This project is archived, so its register is read-only.' : 'You have read-only access to this business.' }}</p>
            @endif
        </form>
    </section>

    <aside class="pg-s4" style="display:grid;gap:var(--pg-gap);align-content:start">
        <section class="pg-card" aria-labelledby="rating-h">
            <div class="pg-card-head"><h2 id="rating-h">Rating</h2><small>{{ $matrix->size }} by {{ $matrix->size }}</small></div>
            <dl class="pg-kv">
                <dt>Before mitigation</dt>
                <dd>@if ($score)@php $b = $matrix->band($score); @endphp<span class="pg-pill {{ $pill[$b] }}">{{ ucfirst($b) }} {{ $score }}</span>@endif</dd>
                <dt>After mitigation</dt>
                <dd>@if ($rscore)@php $rb = $matrix->band($rscore); @endphp<span class="pg-pill {{ $pill[$rb] }}">{{ ucfirst($rb) }} {{ $rscore }}</span>@else<span class="pg-muted">Not rated</span>@endif</dd>
            </dl>
            <div class="pg-rm pg-rm--mini" style="--n:{{ $matrix->size }}" role="img" aria-label="On the matrix: {{ $matrix->probabilityLabel($p) }} and {{ mb_strtolower($matrix->impactLabel($i)) }} before mitigation{{ $rscore ? ', '.$matrix->probabilityLabel($rp).' and '.mb_strtolower($matrix->impactLabel($ri)).' after' : '' }}">
                @for ($y = $matrix->size; $y >= 1; $y--)
                    @for ($x = 1; $x <= $matrix->size; $x++)
                        <span class="pg-rm-cell b-{{ $matrix->band($y * $x) }}">@if ($y === $p && $x === $i)<i class="pg-rm-dot"></i>@endif @if ($rscore && $y === $rp && $x === $ri)<i class="pg-rm-ring"></i>@endif</span>
                    @endfor
                @endfor
            </div>
            <div class="pg-key" style="margin-top:10px"><span><i class="pg-rm-dot" style="position:static"></i>Before</span>@if ($rscore)<span><i class="pg-rm-ring" style="position:static"></i>After</span>@endif<span class="pg-muted">Up is more likely, right is worse</span></div>
        </section>

        <section class="pg-card" aria-labelledby="esc-h">
            <div class="pg-card-head"><h2 id="esc-h">Escalation</h2></div>
            @if ($risk->escalated_at)
                <p style="margin:0 0 4px"><b>Escalated to {{ $project->owner?->name ?? 'the project owner' }}</b> by {{ $risk->escalator?->name ?? 'someone' }} on {{ $risk->escalated_at->format('j M Y') }}.</p>
                <p class="pg-help" style="margin:0 0 10px;white-space:pre-line">{{ $risk->escalation_note }}</p>
                @if ($canEdit)<button type="button" class="pg-btn pg-btn--secondary pg-btn--sm" wire:click="withdraw">Close the escalation</button>@endif
            @elseif ($canEdit && $live)
                <form wire:submit="escalate" class="pg-form" style="gap:8px">
                    <label for="rk-esc" class="pg-label" style="margin:0">What do you need from {{ $project->owner?->name ?? 'the project owner' }}?</label>
                    <textarea id="rk-esc" wire:model="escalationNote" rows="2" maxlength="500" class="pg-textarea" placeholder="A decision on the second supplier by Friday."></textarea>
                    @error('escalation')<p class="pg-error">{{ $message }}</p>@enderror
                    <div><button type="submit" class="pg-btn pg-btn--secondary pg-btn--sm">Escalate</button></div>
                </form>
            @else
                <p class="pg-empty">Not escalated.</p>
            @endif
        </section>

        <section class="pg-card" aria-labelledby="hap-h">
            <div class="pg-card-head"><h2 id="hap-h">If it happens</h2></div>
            @if ($issues->isNotEmpty())
                @foreach ($issues as $is)
                    <div class="pg-row"><span><span class="pg-id">{{ $is->ref() }}</span> <a href="{{ route('projects.issues.show', [$project, $is]) }}">{{ $is->title }}</a></span><span class="pg-sub">{{ App\Models\ProjectIssue::STATUSES[$is->status] ?? $is->status }}</span></div>
                @endforeach
            @endif
            @if ($canEdit && $live)
                @if ($confirmingOccurred)
                    <p class="pg-help" style="margin:0 0 8px">The risk closes as happened and an issue opens with the same owner, rated {{ $score ? $matrix->band($score) : '' }} severity.</p>
                    <div class="pg-actions"><button type="button" class="pg-btn pg-btn--primary pg-btn--sm" wire:click="occurred">Open the issue</button><button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('confirmingOccurred', false)">Not yet</button></div>
                @else
                    <button type="button" class="pg-btn pg-btn--secondary pg-btn--sm" wire:click="$set('confirmingOccurred', true)">It happened</button>
                @endif
            @elseif ($issues->isEmpty())
                <p class="pg-empty">{{ $risk->status === 'closed' ? 'Closed without happening.' : 'Nothing recorded.' }}</p>
            @endif
        </section>

        <section class="pg-card" aria-labelledby="hist-h">
            <div class="pg-card-head"><h2 id="hist-h">History</h2></div>
            @foreach ($history as $line)
                <div class="pg-act"><time datetime="{{ $line['at']->toIso8601String() }}">{{ $line['at']->format('j M, H:i') }}</time><span>@if ($line['who'])<b style="font-weight:500">{{ $line['who'] }}</b> @endif{{ $line['text'] }}</span></div>
            @endforeach
            <p class="pg-help" style="margin:8px 0 0">Raised by {{ $risk->created_by ? (App\Models\User::find($risk->created_by)?->name ?? 'someone') : 'someone' }} on {{ $risk->created_at->format('j M Y') }}.</p>
        </section>
    </aside>
</div>
