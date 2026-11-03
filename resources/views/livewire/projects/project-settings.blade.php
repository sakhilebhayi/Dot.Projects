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

    <section class="pg-card pg-s8 pg-wf" aria-labelledby="wf-h">
        <div class="pg-card-head"><h2 id="wf-h">Workflow</h2><small>The board's columns, in order</small></div>
        <p class="pg-help pg-wf-lead">Each status means one of five things, so done still counts as done, blocked work still cannot start and reports, automations and the overview still add up, whatever the statuses are called.</p>
        @error('workflow')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
        <ol class="pg-wf-list">
            @foreach ($workflow as $i => $st)
                <li class="pg-wf-row" wire:key="wf-{{ $st['key'] }}">
                    @if ($customWorkflow && $renaming === $st['id'])
                        <form wire:submit="saveStatusName" class="pg-wf-edit">
                            <label for="wf-name-{{ $st['id'] }}" class="pg-sr">New name for {{ $st['name'] }}</label>
                            <input id="wf-name-{{ $st['id'] }}" type="text" wire:model="statusName" maxlength="40" class="pg-input" required>
                            <button type="submit" class="pg-btn pg-btn--primary pg-btn--sm">Save</button>
                            <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('renaming', null)">Cancel</button>
                        </form>
                    @else
                        <div class="pg-wf-what">
                            <b>{{ $st['name'] }}</b>
                            <span>{{ $st['name'] === App\Services\ProjectStatuses::CATEGORIES[$st['category']] ? ucfirst(App\Services\ProjectStatuses::MEANS[$st['category']]) : 'Means '.mb_strtolower(App\Services\ProjectStatuses::CATEGORIES[$st['category']]).': '.App\Services\ProjectStatuses::MEANS[$st['category']] }}. {{ ($taskCounts[$st['key']] ?? 0) === 0 ? 'No tasks' : ($taskCounts[$st['key']].' '.Str::plural('task', $taskCounts[$st['key']])) }}.</span>
                        </div>
                        @if ($canWorkflow && $customWorkflow)
                            <div class="pg-wf-act">
                                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm pg-btn--icon" wire:click="moveStatus({{ $st['id'] }}, -1)" aria-label="Move {{ $st['name'] }} earlier" @disabled($i === 0)><span class="material-symbols-rounded" aria-hidden="true">arrow_upward</span></button>
                                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm pg-btn--icon" wire:click="moveStatus({{ $st['id'] }}, 1)" aria-label="Move {{ $st['name'] }} later" @disabled($i === $workflow->count() - 1)><span class="material-symbols-rounded" aria-hidden="true">arrow_downward</span></button>
                                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="renameStatus({{ $st['id'] }})">Rename</button>
                                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="removeStatus({{ $st['id'] }})">Remove</button>
                            </div>
                        @endif
                    @endif
                    @if ($customWorkflow && $removing === $st['id'])
                        <form wire:submit="confirmRemoveStatus" class="pg-wf-remove">
                            <label for="wf-to" class="pg-label">{{ ($taskCounts[$st['key']] ?? 0) === 0 ? 'It has no tasks. Remove it?' : 'Move its '.$taskCounts[$st['key']].' '.Str::plural('task', $taskCounts[$st['key']]).' to' }}</label>
                            <div class="pg-wf-edit">
                                <select id="wf-to" wire:model="moveTo" class="pg-select" @if (($taskCounts[$st['key']] ?? 0) === 0) hidden @endif>
                                    @foreach ($workflow as $other)@continue($other['id'] === $st['id'])<option value="{{ $other['key'] }}">{{ $other['name'] }}</option>@endforeach
                                </select>
                                <button type="submit" class="pg-btn pg-btn--danger pg-btn--sm">Remove {{ $st['name'] }}</button>
                                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('removing', null)">Cancel</button>
                            </div>
                        </form>
                    @endif
                </li>
            @endforeach
        </ol>
        @if ($canWorkflow)
            @if ($customWorkflow)
                <form wire:submit="addStatus" class="pg-wf-add">
                    <div class="pg-field"><label for="wf-new" class="pg-label">New status</label><input id="wf-new" type="text" wire:model="newStatusName" maxlength="40" class="pg-input" placeholder="Awaiting client" required></div>
                    <div class="pg-field"><label for="wf-cat" class="pg-label">It means</label>
                        <select id="wf-cat" wire:model="newStatusCategory" class="pg-select">
                            @foreach (App\Services\ProjectStatuses::CATEGORIES as $c => $label)<option value="{{ $c }}">{{ $label }}: {{ App\Services\ProjectStatuses::MEANS[$c] }}</option>@endforeach
                        </select>
                    </div>
                    <button type="submit" class="pg-btn pg-btn--secondary">Add status</button>
                </form>
                <p class="pg-wf-foot"><button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="resetWorkflow" wire:confirm="Go back to the standard five statuses? Every task keeps what its status means; the names you chose are removed.">Back to the standard five</button></p>
            @else
                <div class="pg-wf-foot pg-wf-start">
                    <p class="pg-help" style="margin:0">This project uses the standard five. Make them its own to rename, reorder, add or remove statuses.</p>
                    <button type="button" class="pg-btn pg-btn--secondary" wire:click="customiseWorkflow">Make the workflow this project's own</button>
                </div>
            @endif
        @endif
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
