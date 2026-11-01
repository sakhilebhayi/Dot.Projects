<div class="pg-grid">
    <section class="pg-card pg-s8" aria-labelledby="mtg-h">
        <h2 id="mtg-h" class="pg-sr">Meeting record</h2>
        <form wire:submit="save" class="pg-form">
            <fieldset @disabled(! $canEdit) style="border:0;margin:0;padding:0;display:grid;gap:14px;min-width:0">
                <div class="pg-form-row">
                    <div class="pg-field">
                        <label for="mt-title" class="pg-label"><span class="pg-id">{{ $meeting->ref() }}</span> Meeting</label>
                        <input id="mt-title" type="text" wire:model="title" maxlength="255" class="pg-input" style="font-size:16px;font-weight:500" required>
                        @error('title')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="mt-kind" class="pg-label">Kind</label>
                        <select id="mt-kind" wire:model="kind" class="pg-select">
                            @foreach (App\Models\ProjectMeeting::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="pg-form-row">
                    <div class="pg-field">
                        <label for="mt-when" class="pg-label">When</label>
                        <input id="mt-when" type="datetime-local" wire:model="heldAt" class="pg-input" required>
                        @error('held_at')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="mt-where" class="pg-label">Where</label>
                        <input id="mt-where" type="text" wire:model="location" maxlength="255" class="pg-input">
                    </div>
                </div>
                <fieldset class="pg-fieldset">
                    <legend>Who attended</legend>
                    <div class="pg-people-picks">
                        @foreach ($people as $person)
                            <label class="pg-radio"><input type="checkbox" wire:model="attendees" value="{{ $person->id }}"> {{ $person->name }}</label>
                        @endforeach
                    </div>
                    @error('attendees')<p class="pg-error">{{ $message }}</p>@enderror
                    <div class="pg-field" style="margin-top:10px">
                        <label for="mt-guests" class="pg-label">Guests from outside the business</label>
                        <input id="mt-guests" type="text" wire:model="guests" maxlength="500" class="pg-input" placeholder="Names and organisations">
                    </div>
                </fieldset>
                <div class="pg-field">
                    <label for="mt-agenda" class="pg-label">Agenda</label>
                    <textarea id="mt-agenda" wire:model="agenda" rows="4" maxlength="20000" class="pg-textarea" placeholder="One item per line."></textarea>
                </div>
                <div class="pg-field">
                    <label for="mt-minutes" class="pg-label">Minutes</label>
                    <textarea id="mt-minutes" wire:model="minutes" rows="10" maxlength="50000" class="pg-textarea" placeholder="What was discussed, item by item. Record decisions and actions on the right so they reach their registers."></textarea>
                    @error('minutes')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
            </fieldset>
            @if ($canEdit)
                <div class="pg-actions" style="justify-content:flex-end;align-items:center;border-top:1px solid var(--pg-rule);padding-top:14px">
                    @if (session('saved'))<span class="pg-help" role="status" style="margin:0">{{ session('saved') }}</span>@endif
                    <button type="submit" class="pg-btn pg-btn--primary" wire:loading.attr="disabled" wire:target="save">Save the record</button>
                </div>
            @else
                <p class="pg-help" style="margin:0">{{ $project->isArchived() ? 'This project is archived, so its meetings are read-only.' : 'You have read-only access to this business.' }}</p>
            @endif
        </form>
    </section>

    <aside class="pg-s4" style="display:grid;gap:var(--pg-gap);align-content:start">
        <section class="pg-card" aria-labelledby="md-h">
            <div class="pg-card-head"><h2 id="md-h">Decisions taken</h2><small>{{ $decisions->count() }}</small></div>
            @foreach ($decisions as $d)
                <div class="pg-row"><span><span class="pg-id">{{ $d->ref() }}</span> <a href="{{ route('projects.decisions.show', [$project, $d]) }}">{{ $d->title }}</a></span></div>
            @endforeach
            @if ($canEdit)
                <form wire:submit="addDecision" class="pg-form" style="gap:8px;margin-top:{{ $decisions->isEmpty() ? '0' : '10px' }}">
                    <label for="md-title" class="pg-label" style="margin:0">Decision</label>
                    <input id="md-title" type="text" wire:model="decisionTitle" maxlength="255" class="pg-input" placeholder="Night shift runs 18:00 to 06:00">
                    @error('decision_title')<p class="pg-error">{{ $message }}</p>@enderror
                    <label for="md-out" class="pg-label" style="margin:0">What was agreed</label>
                    <textarea id="md-out" wire:model="decisionOutcome" rows="2" maxlength="5000" class="pg-textarea"></textarea>
                    @error('decision_outcome')<p class="pg-error">{{ $message }}</p>@enderror
                    <div><button type="submit" class="pg-btn pg-btn--secondary pg-btn--sm">Record the decision</button></div>
                </form>
            @elseif ($decisions->isEmpty())
                <p class="pg-empty">None recorded.</p>
            @endif
        </section>

        <section class="pg-card" aria-labelledby="ma-h">
            <div class="pg-card-head"><h2 id="ma-h">Actions agreed</h2><small>{{ $actions->where('status', 'open')->count() }} open</small></div>
            @if ($actions->isNotEmpty())
                <ul class="pg-checks">
                    @foreach ($actions as $a)
                        <li class="{{ $a->status === 'done' ? 'is-done' : '' }}" wire:key="ma-{{ $a->id }}">
                            <input type="checkbox" class="pg-check" id="ma-{{ $a->id }}" @checked($a->status === 'done') @disabled(! $canEdit) wire:click="toggleAction({{ $a->id }})">
                            <label for="ma-{{ $a->id }}"><span class="pg-id">{{ $a->ref() }}</span> {{ $a->title }}<span class="pg-sub {{ $a->overdue() ? 'pg-neg' : '' }}" style="display:block">{{ $a->owner?->name ?? 'Nobody' }}{{ $a->due_date ? ', by '.$a->due_date->format('j M') : '' }}</span></label>
                            <span></span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($canEdit)
                <form wire:submit="addAction" class="pg-form" style="gap:8px;margin-top:10px">
                    <label for="ma-title" class="pg-label" style="margin:0">Action</label>
                    <input id="ma-title" type="text" wire:model="actionTitle" maxlength="255" class="pg-input" placeholder="Confirm crane booking with the supplier">
                    <div class="pg-form-row">
                        <div class="pg-field">
                            <label for="ma-owner" class="pg-label">Who</label>
                            <select id="ma-owner" wire:model="actionOwner" class="pg-select">
                                <option value="">Nobody yet</option>
                                @foreach ($people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="pg-field">
                            <label for="ma-due" class="pg-label">By</label>
                            <input id="ma-due" type="date" wire:model="actionDue" class="pg-input">
                        </div>
                    </div>
                    @error('action_title')<p class="pg-error">{{ $message }}</p>@enderror
                    @error('action_owner_id')<p class="pg-error">{{ $message }}</p>@enderror
                    <div><button type="submit" class="pg-btn pg-btn--secondary pg-btn--sm">Add the action</button></div>
                </form>
            @elseif ($actions->isEmpty())
                <p class="pg-empty">None agreed.</p>
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
