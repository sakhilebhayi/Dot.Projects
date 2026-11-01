<div>
    @php
        $proposed = $this->all->where('status', 'proposed');
        $overdue = $proposed->filter(fn ($d) => $d->overdue());
        $next = $proposed->whereNotNull('decide_by')->sortBy('decide_by')->first();
        $statusPill = ['proposed' => 'pg-pill--warning', 'decided' => '', 'superseded' => ''];
    @endphp

    <div class="pg-titlerow" style="margin-top:0">
        <p class="pg-finding" style="margin:0">
            @if ($this->all->isEmpty())
                No decisions recorded. Keep the ones that shape the project here, with what was weighed and who decided, so nobody has to remember.
            @elseif ($proposed->isEmpty())
                Nothing waits on a decision. {{ $this->all->count() }} {{ Str::plural('decision', $this->all->count()) }} on record.
            @else
                <b>{{ $proposed->count() }} {{ Str::plural('decision', $proposed->count()) }} {{ $proposed->count() === 1 ? 'needs' : 'need' }} making</b>{{ $overdue->isNotEmpty() ? ', '.$overdue->count().' past '.($overdue->count() === 1 ? 'its' : 'their').' date' : '' }}.
                @if ($next) Next: {{ $next->title }}, by {{ $next->decide_by->format('j M') }}{{ $next->owner ? ' ('.$next->owner->name.')' : '' }}.@endif
            @endif
        </p>
        @if ($canEdit)
            <div class="pg-actions"><a href="#add-decision" class="pg-btn pg-btn--primary" x-data x-on:click.prevent="document.getElementById('add-decision').open = true; $nextTick(() => document.getElementById('d-title').focus())">Add a decision</a></div>
        @endif
    </div>

    @if ($canEdit)
        <details id="add-decision" class="pg-card pg-add" @if ($errors->any()) open @endif style="margin-bottom:var(--pg-gap)" x-data="{ mode: @entangle('mode') }">
            <summary class="pg-card-head" style="cursor:pointer;margin:0"><h2>Add a decision</h2><small>Ask for one, or record one already made</small></summary>
            <form wire:submit="add" class="pg-form" style="margin-top:12px">
                <div class="pg-seg" role="group" aria-label="Kind of entry" style="justify-self:start">
                    <button type="button" x-on:click="mode = 'ask'" x-bind:aria-pressed="mode === 'ask'">Needs deciding</button>
                    <button type="button" x-on:click="mode = 'record'" x-bind:aria-pressed="mode === 'record'">Already decided</button>
                </div>
                <div class="pg-field">
                    <label for="d-title" class="pg-label">The question or decision</label>
                    <input id="d-title" type="text" wire:model="title" maxlength="255" class="pg-input" placeholder="Use the second supplier for the conveyor parts?" required>
                    @error('title')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-form-row" x-show="mode === 'ask'">
                    <div class="pg-field">
                        <label for="d-owner" class="pg-label">Who decides</label>
                        <select id="d-owner" wire:model="owner" class="pg-select">
                            <option value="">Not set</option>
                            @foreach ($people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                        </select>
                        @error('owner_id')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="d-by" class="pg-label">Needed by</label>
                        <input id="d-by" type="date" wire:model="decideBy" class="pg-input">
                        @error('decide_by')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="pg-field" x-show="mode === 'record'" x-cloak>
                    <label for="d-outcome" class="pg-label">What was decided</label>
                    <textarea id="d-outcome" wire:model="outcome" rows="3" maxlength="5000" class="pg-textarea"></textarea>
                    @error('outcome')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div><button type="submit" class="pg-btn pg-btn--primary">Add to the register</button></div>
            </form>
        </details>
    @endif

    <section class="pg-table-wrap" aria-labelledby="dr-h">
        <div class="pg-filters" style="padding:12px 16px 4px;margin:0">
            <h2 id="dr-h" style="font-size:14px;font-weight:600;margin:0 auto 0 0">Decision register</h2>
            <label for="dr-show" class="pg-sr">Which decisions</label>
            <select id="dr-show" wire:model.live="show" class="pg-select" style="width:auto">
                <option value="all">All</option>
                <option value="proposed">Needs deciding</option>
                <option value="decided">Decided</option>
                <option value="superseded">Superseded</option>
            </select>
        </div>
        @if ($this->decisions->isEmpty())
            <p class="pg-empty" style="padding:8px 16px 16px">{{ $this->all->isEmpty() ? 'The register lists each decision, who made it and when, and any change request it settled.' : 'No decision matches.' }}</p>
        @else
            <table class="pg-table pg-table--decisions">
                <thead><tr><th scope="col">Decision</th><th scope="col">Who</th><th scope="col" class="r">When</th><th scope="col">Status</th></tr></thead>
                <tbody>
                    @foreach ($this->decisions as $d)
                        <tr wire:key="dec-{{ $d->id }}">
                            <td class="pg-name"><span class="pg-id">{{ $d->ref() }}</span> <a href="{{ route('projects.decisions.show', [$project, $d]) }}" @if ($d->status === 'superseded') style="color:var(--pg-muted)" @endif>{{ $d->title }}</a>@if ($d->change)<span class="pg-sub">Settles {{ $d->change->ref() }}</span>@endif</td>
                            <td data-label="Who">{{ $d->status === 'proposed' ? ($d->owner?->name ?? 'Not set') : ($d->decider?->name ?? 'Someone') }}</td>
                            <td data-label="When" class="r {{ $d->overdue() ? 'pg-neg' : '' }}">@if ($d->status === 'proposed'){{ $d->decide_by ? 'By '.$d->decide_by->format('j M') : 'No date' }}@else{{ $d->decided_on?->format('j M Y') }}@endif</td>
                            <td data-label="Status"><span class="pg-pill {{ $statusPill[$d->status] ?? '' }}">{{ App\Models\ProjectDecision::STATUSES[$d->status] ?? $d->status }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
