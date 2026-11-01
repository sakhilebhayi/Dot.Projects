<div>
    @php
        $open = $this->all->filter(fn ($i) => $i->isOpen());
        $serious = $open->whereIn('severity', ['high', 'critical'])->count();
        $late = $open->filter(fn ($i) => $i->late())->count();
        $escalated = $open->whereNotNull('escalated_at')->count();
        $sev = ['low' => '', 'medium' => 'pg-pill--warning', 'high' => 'pg-pill--negative', 'critical' => 'pg-pill--negative pg-pill--solid'];
    @endphp

    <div class="pg-titlerow" style="margin-top:0">
        <p class="pg-finding" style="margin:0">
            @if ($this->all->isEmpty())
                No issues logged. An issue is something that has already gone wrong and needs a corrective action.
            @elseif ($open->isEmpty())
                No open issues. {{ $this->all->count() }} resolved or closed.
            @else
                <b>{{ $open->count() }} open {{ Str::plural('issue', $open->count()) }}</b>{{ $serious || $late || $escalated ? ':' : '.' }}
                @php $parts = array_filter([$serious ? $serious.' high or critical' : null, $late ? $late.' past the target date' : null, $escalated ? $escalated.' escalated' : null]); @endphp
                @if ($parts){{ implode(', ', $parts) }}.@endif
            @endif
        </p>
        @if ($canEdit)
            <div class="pg-actions"><a href="#add-issue" class="pg-btn pg-btn--primary" x-data x-on:click.prevent="document.getElementById('add-issue').open = true; $nextTick(() => document.getElementById('i-title').focus())">Log an issue</a></div>
        @endif
    </div>

    @if ($canEdit)
        <details id="add-issue" class="pg-card pg-add" @if ($errors->any()) open @endif style="margin-bottom:var(--pg-gap)">
            <summary class="pg-card-head" style="cursor:pointer;margin:0"><h2>Log an issue</h2><small>Triage it now; cause and action go on its page</small></summary>
            <form wire:submit="add" class="pg-form" style="margin-top:12px">
                <div class="pg-field">
                    <label for="i-title" class="pg-label">What has happened</label>
                    <input id="i-title" type="text" wire:model="title" maxlength="255" class="pg-input" placeholder="The crane failed inspection on site" required>
                    @error('title')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-form-row pg-form-row--3">
                    <div class="pg-field">
                        <label for="i-sev" class="pg-label">Severity</label>
                        <select id="i-sev" wire:model="newSeverity" class="pg-select">
                            @foreach (App\Models\ProjectIssue::SEVERITIES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="pg-field">
                        <label for="i-pri" class="pg-label">Priority</label>
                        <select id="i-pri" wire:model="priority" class="pg-select">
                            @foreach (App\Services\ProjectWork::PRIORITIES as $value)<option value="{{ $value }}">{{ ucfirst($value) }}</option>@endforeach
                        </select>
                    </div>
                    <div class="pg-field">
                        <label for="i-cat" class="pg-label">Category</label>
                        <select id="i-cat" wire:model="category" class="pg-select">
                            @foreach (App\Services\Governance::CATEGORIES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="pg-form-row pg-form-row--3">
                    <div class="pg-field">
                        <label for="i-owner" class="pg-label">Owner</label>
                        <select id="i-owner" wire:model="owner" class="pg-select">
                            <option value="">Nobody yet</option>
                            @foreach ($people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                        </select>
                        @error('owner_id')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="i-target" class="pg-label">Resolve by</label>
                        <input id="i-target" type="date" wire:model="target" class="pg-input">
                        @error('target_date')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field" style="align-self:end">
                        <button type="submit" class="pg-btn pg-btn--primary">Log the issue</button>
                    </div>
                </div>
            </form>
        </details>
    @endif

    <section class="pg-table-wrap" aria-labelledby="log-h">
        <div class="pg-filters" style="padding:12px 16px 4px;margin:0">
            <h2 id="log-h" style="font-size:14px;font-weight:600;margin:0 auto 0 0">Issue log</h2>
            <label for="log-sev" class="pg-sr">Severity</label>
            <select id="log-sev" wire:model.live="severity" class="pg-select" style="width:auto">
                <option value="">Any severity</option>
                @foreach (App\Models\ProjectIssue::SEVERITIES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <label for="log-show" class="pg-sr">Which issues</label>
            <select id="log-show" wire:model.live="show" class="pg-select" style="width:auto">
                <option value="open">Open</option>
                <option value="done">Resolved or closed</option>
                <option value="all">All</option>
            </select>
        </div>
        @if ($this->issues->isEmpty())
            <p class="pg-empty" style="padding:8px 16px 16px">{{ $this->all->isEmpty() ? 'The log lists every issue with its severity, owner and target date.' : 'No issue matches.' }}</p>
        @else
            <table class="pg-table pg-table--issues">
                <thead><tr><th scope="col">Issue</th><th scope="col">Severity</th><th scope="col">Priority</th><th scope="col">Owner</th><th scope="col" class="r">Resolve by</th><th scope="col">Status</th></tr></thead>
                <tbody>
                    @foreach ($this->issues as $is)
                        <tr wire:key="issue-{{ $is->id }}">
                            <td class="pg-name"><span class="pg-id">{{ $is->ref() }}</span> <a href="{{ route('projects.issues.show', [$project, $is]) }}" @if (! $is->isOpen()) style="color:var(--pg-muted)" @endif>{{ $is->title }}</a><span class="pg-sub">{{ App\Services\Governance::CATEGORIES[$is->category] ?? 'Other' }}@if ($is->risk), from {{ $is->risk->ref() }}@endif @if ($is->escalated_at && $is->isOpen()), escalated @endif</span></td>
                            <td data-label="Severity"><span class="pg-pill {{ $sev[$is->severity] ?? '' }}">{{ App\Models\ProjectIssue::SEVERITIES[$is->severity] ?? $is->severity }}</span></td>
                            <td data-label="Priority">{{ ucfirst($is->priority) }}</td>
                            <td data-label="Owner" class="{{ $is->owner ? '' : 'pg-muted' }}">{{ $is->owner?->name ?? 'Nobody' }}</td>
                            <td data-label="Resolve by" class="r {{ $is->late() ? 'pg-neg' : ($is->target_date ? '' : 'pg-muted') }}">{{ $is->target_date?->format('j M') ?? '–' }}</td>
                            <td data-label="Status">{{ App\Models\ProjectIssue::STATUSES[$is->status] ?? $is->status }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
