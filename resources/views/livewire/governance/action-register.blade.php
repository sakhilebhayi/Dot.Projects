<div>
    @php
        $open = $this->all->where('status', 'open');
        $late = $open->filter(fn ($a) => $a->overdue());
        $unowned = $open->whereNull('owner_id')->count();
    @endphp

    <div class="pg-titlerow" style="margin-top:0">
        <p class="pg-finding" style="margin:0">
            @if ($this->all->isEmpty())
                No actions yet. Actions agreed in meetings land here, and you can add one on its own.
            @elseif ($open->isEmpty())
                Every action is done.
            @else
                <b>{{ $open->count() }} open {{ Str::plural('action', $open->count()) }}</b>{{ $late->isNotEmpty() ? ', '.$late->count().' late' : '' }}{{ $unowned ? ', '.$unowned.' with nobody on '.($unowned === 1 ? 'it' : 'them') : '' }}.
            @endif
        </p>
    </div>

    @if ($canEdit)
        <form wire:submit="add" class="pg-card pg-quick-action" style="margin-bottom:var(--pg-gap)">
            <div class="pg-field" style="flex:1 1 280px">
                <label for="ar-title" class="pg-label">Add an action</label>
                <input id="ar-title" type="text" wire:model="title" maxlength="255" class="pg-input" placeholder="Send the revised shutdown plan to the client" required>
                @error('title')<p class="pg-error">{{ $message }}</p>@enderror
            </div>
            <div class="pg-field" style="flex:0 1 200px">
                <label for="ar-owner" class="pg-label">Who</label>
                <select id="ar-owner" wire:model="owner" class="pg-select">
                    <option value="">Nobody yet</option>
                    @foreach ($people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                </select>
                @error('owner_id')<p class="pg-error">{{ $message }}</p>@enderror
            </div>
            <div class="pg-field" style="flex:0 1 170px">
                <label for="ar-due" class="pg-label">By</label>
                <input id="ar-due" type="date" wire:model="due" class="pg-input">
            </div>
            <div style="align-self:end"><button type="submit" class="pg-btn pg-btn--primary">Add</button></div>
        </form>
    @endif

    <section class="pg-table-wrap" aria-labelledby="ar-h">
        <div class="pg-filters" style="padding:12px 16px 4px;margin:0">
            <h2 id="ar-h" style="font-size:14px;font-weight:600;margin:0 auto 0 0">Action register</h2>
            <label for="ar-who" class="pg-sr">Whose actions</label>
            <select id="ar-who" wire:model.live="who" class="pg-select" style="width:auto">
                <option value="">Everyone</option>
                @foreach ($people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
            </select>
            <label for="ar-show" class="pg-sr">Which actions</label>
            <select id="ar-show" wire:model.live="show" class="pg-select" style="width:auto">
                <option value="open">Open</option>
                <option value="done">Done</option>
                <option value="all">All</option>
            </select>
        </div>
        @error('due_date')<p class="pg-error" style="padding:0 16px">{{ $message }}</p>@enderror
        @if ($this->actions->isEmpty())
            <p class="pg-empty" style="padding:8px 16px 16px">{{ $this->all->isEmpty() ? 'The register lists every action, who owns it and by when.' : 'No action matches.' }}</p>
        @else
            <table class="pg-table pg-table--actions">
                <thead><tr><th scope="col" style="width:36px"><span class="pg-sr">Done</span></th><th scope="col">Action</th><th scope="col">Who</th><th scope="col">By</th><th scope="col">From</th></tr></thead>
                <tbody>
                    @foreach ($this->actions as $a)
                        <tr wire:key="act-{{ $a->id }}">
                            <td data-label="Status"><input type="checkbox" class="pg-check" style="position:relative;z-index:1" aria-label="Done: {{ $a->title }}" @checked($a->status === 'done') @disabled(! $canEdit) wire:click="toggle({{ $a->id }})"></td>
                            <td class="pg-name"><span class="pg-id">{{ $a->ref() }}</span> <span style="{{ $a->status === 'done' ? 'color:var(--pg-muted);text-decoration:line-through' : 'font-weight:500' }}">{{ $a->title }}</span></td>
                            <td data-label="Who">
                                @if ($canEdit)
                                    <label for="ar-o-{{ $a->id }}" class="pg-sr">Who does {{ $a->title }}</label>
                                    <select id="ar-o-{{ $a->id }}" class="pg-select" style="min-height:30px;padding:3px 8px;width:auto;max-width:180px" wire:change="setOwner({{ $a->id }}, $event.target.value || null)">
                                        <option value="" @selected(! $a->owner_id)>Nobody</option>
                                        @foreach ($people as $person)<option value="{{ $person->id }}" @selected($a->owner_id === $person->id)>{{ $person->name }}</option>@endforeach
                                    </select>
                                @else
                                    {{ $a->owner?->name ?? 'Nobody' }}
                                @endif
                            </td>
                            <td data-label="By" class="{{ $a->overdue() ? 'pg-neg' : '' }}">
                                @if ($canEdit)
                                    <label for="ar-d-{{ $a->id }}" class="pg-sr">Due date of {{ $a->title }}</label>
                                    <input id="ar-d-{{ $a->id }}" type="date" class="pg-input" style="min-height:30px;padding:3px 8px;width:auto" value="{{ $a->due_date?->toDateString() }}" wire:change="setDue({{ $a->id }}, $event.target.value || null)">
                                    @if ($a->overdue())<span class="pg-sub pg-neg">Late</span>@endif
                                @else
                                    {{ $a->due_date?->format('j M') ?? '–' }}
                                @endif
                            </td>
                            <td data-label="From">@if ($a->meeting)<a href="{{ route('projects.meetings.show', [$project, $a->meeting]) }}" style="position:relative;z-index:1"><span class="pg-id">{{ $a->meeting->ref() }}</span> {{ Str::limit($a->meeting->title, 30) }}</a>@else<span class="pg-muted">Added here</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
