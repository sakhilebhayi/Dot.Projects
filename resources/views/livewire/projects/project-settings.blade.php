<div class="pg-grid">
    @php($canEdit = auth()->user()->can('update', $project))
    <section class="pg-card pg-s8" aria-labelledby="details-h">
        <div class="pg-card-head"><h2 id="details-h">Details</h2></div>
        <form wire:submit="save" class="pg-form">
            <fieldset @disabled(! $canEdit) style="border:0;margin:0;padding:0;display:grid;gap:14px;min-width:0">
                <div class="pg-field">
                    <label for="p-name" class="pg-label">Name</label>
                    <input id="p-name" type="text" wire:model="name" maxlength="255" class="pg-input" required>
                    @error('name')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-field">
                    <label for="p-desc" class="pg-label">What it should achieve</label>
                    <textarea id="p-desc" wire:model="description" rows="4" maxlength="5000" class="pg-textarea"></textarea>
                    @error('description')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-form-row pg-form-row--3">
                    <div class="pg-field">
                        <label for="p-status" class="pg-label">Status</label>
                        @if (array_key_exists($project->status, $statuses))
                            <select id="p-status" wire:model="status" class="pg-select">
                                @foreach ($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                        @else
                            <input id="p-status" type="text" class="pg-input" value="{{ App\Http\Controllers\DashboardController::STATUSES[$project->status] ?? $project->status }}" disabled>
                            <p class="pg-help">Set by the closure on the board.</p>
                        @endif
                    </div>
                    <div class="pg-field">
                        <label for="p-start" class="pg-label">Starts</label>
                        <input id="p-start" type="date" wire:model="startDate" class="pg-input">
                    </div>
                    <div class="pg-field">
                        <label for="p-due" class="pg-label">Due</label>
                        <input id="p-due" type="date" wire:model="dueDate" class="pg-input">
                        @error('dueDate')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="pg-field">
                    <label for="p-method" class="pg-label">How it is run</label>
                    <select id="p-method" wire:model.live="methodology" class="pg-select">
                        <option value="">Not said</option>
                        @foreach (App\Support\Methodologies::ALL as $key => [$label])<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                    <p class="pg-help">{{ $methodology !== '' ? App\Support\Methodologies::ALL[$methodology][1] ?? '' : 'Every view works whichever you choose; this says how the project is run and where to follow it.' }}</p>
                    @error('methodology')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
            </fieldset>
            @if ($canEdit)
                <div class="pg-actions" style="justify-content:flex-end;align-items:center;border-top:1px solid var(--pg-rule);padding-top:14px">
                    @if (session('settings-saved'))<span class="pg-help" role="status" style="margin:0">{{ session('settings-saved') }}</span>@endif
                    <button type="submit" class="pg-btn pg-btn--primary">Save changes</button>
                </div>
            @elseif ($project->isArchived())
                <p class="pg-help" style="margin:0">Archived projects are read-only. Restore it to change anything.</p>
            @endif
        </form>
    </section>

    <aside class="pg-s4" style="display:grid;gap:var(--pg-gap);align-content:start">
        @can('duplicate', $project)
            <section class="pg-card" aria-labelledby="dup-h">
                <div class="pg-card-head"><h2 id="dup-h">Duplicate</h2></div>
                <p class="pg-help" style="margin:0 0 10px">A new project with the same milestones, tasks, subtasks, checklists, labels, dependencies, dates and people, all set to not started. Comments and history stay here.</p>
                <form wire:submit="duplicate" class="pg-form" style="gap:8px">
                    <label for="p-copy" class="pg-label" style="margin:0">Name of the copy</label>
                    <input id="p-copy" type="text" wire:model="copyName" maxlength="255" class="pg-input" required>
                    @error('copyName')<p class="pg-error">{{ $message }}</p>@enderror
                    <div><button type="submit" class="pg-btn pg-btn--secondary">Duplicate project</button></div>
                </form>
            </section>
        @endcan

        @if ($canTemplate)
            <section class="pg-card" aria-labelledby="tpl-h">
                <div class="pg-card-head"><h2 id="tpl-h">Save as a template</h2></div>
                <p class="pg-help" style="margin:0 0 10px">Anyone in the business can then start a project shaped like this one. It keeps the milestones, tasks, subtasks, checklists, dependencies, labels, gates, open risks and budget line names, with dates counted from the start. It leaves out people, progress, time, costs and amounts.</p>
                <form wire:submit="saveTemplate" class="pg-form" style="gap:8px">
                    <label for="p-tname" class="pg-label" style="margin:0">Template name</label>
                    <input id="p-tname" type="text" wire:model="templateName" maxlength="120" class="pg-input" required>
                    <label for="p-tdesc" class="pg-label" style="margin:4px 0 0">What it is for <span class="pg-muted">(optional)</span></label>
                    <input id="p-tdesc" type="text" wire:model="templateDescription" maxlength="500" class="pg-input">
                    @error('templateName')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
                    <div><button type="submit" class="pg-btn pg-btn--secondary">Save as template</button></div>
                </form>
            </section>
        @endif

        @can('archive', $project)
            <section class="pg-card" aria-labelledby="arc-h">
                <div class="pg-card-head"><h2 id="arc-h">{{ $project->isArchived() ? 'Archived' : 'Archive' }}</h2></div>
                @if ($project->isArchived())
                    <p class="pg-help" style="margin:0 0 10px">Archived {{ $project->archived_at->format('j M Y') }}. Everything is kept and read-only.</p>
                    <button type="button" class="pg-btn pg-btn--primary" wire:click="restore">Restore project</button>
                @elseif ($confirmingArchive)
                    <p class="pg-help" style="margin:0 0 10px">The project leaves the list and becomes read-only. Nothing is deleted, and it can be restored.</p>
                    <div class="pg-actions">
                        <button type="button" class="pg-btn pg-btn--danger" wire:click="archive">Archive project</button>
                        <button type="button" class="pg-btn pg-btn--ghost" wire:click="$set('confirmingArchive', false)">Cancel</button>
                    </div>
                @else
                    <p class="pg-help" style="margin:0 0 10px">For finished or abandoned projects you want to keep but not see.</p>
                    <button type="button" class="pg-btn pg-btn--secondary" wire:click="$set('confirmingArchive', true)">Archive…</button>
                @endif
            </section>
        @endcan
    </aside>
</div>
