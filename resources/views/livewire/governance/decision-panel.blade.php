<div class="pg-grid">
    @php
        $proposed = $decision->status === 'proposed';
        $editable = $canEdit && $proposed;
        $statusPill = ['proposed' => 'pg-pill--warning', 'decided' => '', 'superseded' => ''];
    @endphp

    <section class="pg-card pg-s8" aria-labelledby="dec-h">
        <h2 id="dec-h" class="pg-sr">Decision</h2>
        <form wire:submit="save" class="pg-form">
            <fieldset @disabled(! $editable) style="border:0;margin:0;padding:0;display:grid;gap:14px;min-width:0">
                <div class="pg-field">
                    <label for="de-title" class="pg-label"><span class="pg-id">{{ $decision->ref() }}</span> {{ $proposed ? 'The question' : 'The decision' }}</label>
                    <input id="de-title" type="text" wire:model="title" maxlength="255" class="pg-input" style="font-size:16px;font-weight:500" required>
                    @error('title')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-field">
                    <label for="de-context" class="pg-label">Context</label>
                    <textarea id="de-context" wire:model="context" rows="3" maxlength="5000" class="pg-textarea" placeholder="What prompted it and what it affects."></textarea>
                    @error('context')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-field">
                    <label for="de-options" class="pg-label">Options weighed</label>
                    <textarea id="de-options" wire:model="options" rows="3" maxlength="5000" class="pg-textarea" placeholder="Each option with what it costs and what it risks."></textarea>
                    @error('options')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                @if ($proposed)
                    <div class="pg-form-row">
                        <div class="pg-field">
                            <label for="de-owner" class="pg-label">Who decides</label>
                            <select id="de-owner" wire:model="owner" class="pg-select">
                                <option value="">Not set</option>
                                @foreach ($people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                            </select>
                            @error('owner_id')<p class="pg-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="pg-field">
                            <label for="de-by" class="pg-label">Needed by</label>
                            <input id="de-by" type="date" wire:model="decideBy" class="pg-input">
                            @if ($decision->overdue())<p class="pg-help pg-neg" style="margin:4px 0 0">Past its date since {{ $decision->decide_by->format('j M') }}.</p>@endif
                            @error('decide_by')<p class="pg-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                @endif
            </fieldset>
            @error('decision')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
            @if ($editable)
                <div class="pg-actions" style="justify-content:flex-end;align-items:center;border-top:1px solid var(--pg-rule);padding-top:14px">
                    @if (session('saved'))<span class="pg-help" role="status" style="margin:0">{{ session('saved') }}</span>@endif
                    <button type="submit" class="pg-btn pg-btn--secondary" wire:loading.attr="disabled" wire:target="save">Save</button>
                </div>
            @elseif (! $proposed)
                <p class="pg-help" style="margin:0">On record since {{ $decision->decided_on?->format('j M Y') }}. A decision is not edited after it is made; record a new one and mark this one superseded.</p>
            @else
                <p class="pg-help" style="margin:0">{{ $project->isArchived() ? 'This project is archived, so its register is read-only.' : 'You have read-only access to this business.' }}</p>
            @endif
        </form>
    </section>

    <aside class="pg-s4" style="display:grid;gap:var(--pg-gap);align-content:start">
        <section class="pg-card" aria-labelledby="out-h">
            <div class="pg-card-head"><h2 id="out-h">Outcome</h2><span class="pg-pill {{ $statusPill[$decision->status] ?? '' }}">{{ App\Models\ProjectDecision::STATUSES[$decision->status] ?? $decision->status }}</span></div>
            @if ($proposed)
                @if ($canEdit)
                    <form wire:submit="decide" class="pg-form" style="gap:8px">
                        <label for="de-outcome" class="pg-label" style="margin:0">What was decided</label>
                        <textarea id="de-outcome" wire:model="outcome" rows="4" maxlength="5000" class="pg-textarea" placeholder="The choice, and any conditions on it."></textarea>
                        @error('outcome')<p class="pg-error">{{ $message }}</p>@enderror
                        <p class="pg-help" style="margin:0">Recorded as decided by you, today{{ $decision->owner && $decision->owner_id !== auth()->id() ? ', on behalf of '.$decision->owner->name : '' }}.</p>
                        <div><button type="submit" class="pg-btn pg-btn--primary">Record the decision</button></div>
                    </form>
                @else
                    <p class="pg-empty">Waiting on {{ $decision->owner?->name ?? 'a decision' }}{{ $decision->decide_by ? ', by '.$decision->decide_by->format('j M') : '' }}.</p>
                @endif
            @else
                <p style="margin:0 0 6px;white-space:pre-line">{{ $decision->outcome ?? 'No outcome written.' }}</p>
                <p class="pg-help" style="margin:0">Decided by {{ $decision->decider?->name ?? 'someone' }} on {{ $decision->decided_on?->format('j M Y') }}.</p>
                @if ($decision->change)<p class="pg-help" style="margin:6px 0 0">Settles <a href="{{ route('projects.changes.show', [$project, $decision->change]) }}"><span class="pg-id">{{ $decision->change->ref() }}</span> {{ Str::limit($decision->change->title, 40) }}</a>.</p>@endif
                @if ($decision->successor)<p class="pg-help" style="margin:6px 0 0">Superseded by <a href="{{ route('projects.decisions.show', [$project, $decision->successor]) }}"><span class="pg-id">{{ $decision->successor->ref() }}</span> {{ Str::limit($decision->successor->title, 40) }}</a>.</p>@endif
            @endif
        </section>

        @if ($decision->status === 'decided' && $canEdit && $later->isNotEmpty())
            <section class="pg-card" aria-labelledby="sup-h">
                <div class="pg-card-head"><h2 id="sup-h">Replaced since?</h2></div>
                <form wire:submit="supersede" class="pg-form" style="gap:8px">
                    <label for="de-sup" class="pg-label" style="margin:0">The later decision that replaces it</label>
                    <select id="de-sup" wire:model="replacedBy" class="pg-select">
                        <option value="">Choose</option>
                        @foreach ($later as $l)<option value="{{ $l->id }}">{{ $l->ref() }} {{ Str::limit($l->title, 50) }}</option>@endforeach
                    </select>
                    @error('supersede')<p class="pg-error">{{ $message }}</p>@enderror
                    <div><button type="submit" class="pg-btn pg-btn--secondary pg-btn--sm">Mark superseded</button></div>
                </form>
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
