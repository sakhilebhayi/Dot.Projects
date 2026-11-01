<div>
    @php
        $now = now();
        $upcoming = $this->meetings->filter(fn ($m) => $m->held_at->gte($now))->sortBy('held_at')->values();
        $held = $this->meetings->filter(fn ($m) => $m->held_at->lt($now))->values();
        $noMinutes = $held->filter(fn ($m) => trim((string) $m->minutes) === '')->count();
    @endphp

    <div class="pg-titlerow" style="margin-top:0">
        <p class="pg-finding" style="margin:0">
            @if ($this->meetings->isEmpty())
                No meetings recorded. Keep the meetings that steer this project here, with who came, what was decided and who agreed to do what.
            @else
                @if ($upcoming->isNotEmpty())Next: <b>{{ $upcoming->first()->title }}</b>, {{ $upcoming->first()->held_at->format('D j M, H:i') }}.@else No meeting is planned.@endif
                {{ $noMinutes ? $noMinutes.' held '.Str::plural('meeting', $noMinutes).' '.($noMinutes === 1 ? 'has' : 'have').' no minutes yet.' : '' }}
            @endif
        </p>
        @if ($canEdit)
            <div class="pg-actions"><a href="#add-meeting" class="pg-btn pg-btn--primary" x-data x-on:click.prevent="document.getElementById('add-meeting').open = true; $nextTick(() => document.getElementById('m-title').focus())">Add a meeting</a></div>
        @endif
    </div>

    @if ($canEdit)
        <details id="add-meeting" class="pg-card pg-add" @if ($errors->any()) open @endif style="margin-bottom:var(--pg-gap)">
            <summary class="pg-card-head" style="cursor:pointer;margin:0"><h2>Add a meeting</h2><small>Attendees, agenda and minutes go on its page</small></summary>
            <form wire:submit="add" class="pg-form" style="margin-top:12px">
                <div class="pg-form-row">
                    <div class="pg-field">
                        <label for="m-title" class="pg-label">Meeting</label>
                        <input id="m-title" type="text" wire:model="title" maxlength="255" class="pg-input" placeholder="Shutdown readiness review" required>
                        @error('title')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="m-kind" class="pg-label">Kind</label>
                        <select id="m-kind" wire:model="kind" class="pg-select">
                            @foreach (App\Models\ProjectMeeting::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="pg-form-row">
                    <div class="pg-field">
                        <label for="m-when" class="pg-label">When</label>
                        <input id="m-when" type="datetime-local" wire:model="heldAt" class="pg-input" required>
                        @error('held_at')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="m-where" class="pg-label">Where</label>
                        <input id="m-where" type="text" wire:model="location" maxlength="255" class="pg-input" placeholder="Site office, or a call link">
                    </div>
                </div>
                <div><button type="submit" class="pg-btn pg-btn--primary">Add the meeting</button></div>
            </form>
        </details>
    @endif

    @foreach ([['Coming up', $upcoming, 'up'], ['Held', $held, 'held']] as [$heading, $list, $key])
        @if ($list->isNotEmpty())
            <section class="pg-table-wrap" style="margin-bottom:var(--pg-gap)" aria-labelledby="ml-{{ $key }}">
                <h2 id="ml-{{ $key }}" style="font-size:14px;font-weight:600;margin:0;padding:12px 16px 4px">{{ $heading }}</h2>
                <table class="pg-table pg-table--meetings">
                    <thead><tr><th scope="col">Meeting</th><th scope="col">When</th><th scope="col" class="r">Attended</th><th scope="col" class="r">Decisions</th><th scope="col" class="r">Actions</th><th scope="col">Minutes</th></tr></thead>
                    <tbody>
                        @foreach ($list as $m)
                            <tr wire:key="mt-{{ $m->id }}">
                                <td class="pg-name"><span class="pg-id">{{ $m->ref() }}</span> <a href="{{ route('projects.meetings.show', [$project, $m]) }}">{{ $m->title }}</a><span class="pg-sub">{{ App\Models\ProjectMeeting::KINDS[$m->kind] ?? 'Other' }}{{ $m->location ? ', '.$m->location : '' }}</span></td>
                                <td data-label="When">{{ $m->held_at->format('D j M Y, H:i') }}</td>
                                <td data-label="Attended" class="r">{{ $m->attendees_count }}</td>
                                <td data-label="Decisions" class="r">{{ $m->decisions_count }}</td>
                                <td data-label="Actions" class="r">{{ $m->actions_count }}</td>
                                <td data-label="Status">{{ trim((string) $m->minutes) !== '' ? 'Written' : ($key === 'held' ? 'Not yet' : 'After the meeting') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
    @endforeach
    @if ($this->meetings->isEmpty())
        <div class="pg-card"><p class="pg-empty">Meetings appear here, coming up first, then those held with their minutes, decisions and actions.</p></div>
    @endif
</div>
