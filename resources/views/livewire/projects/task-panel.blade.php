<div class="pg-grid">
    @php($canEdit = auth()->user()->can('update', $task))
    @php($done = $this->checklist->whereNotNull('done_at')->count())
    <section class="pg-card pg-s8" aria-labelledby="task-h">
        <h2 id="task-h" class="pg-sr">Task details</h2>
        <form wire:submit="save" class="pg-form">
            <fieldset @disabled(! $canEdit) style="border:0;margin:0;padding:0;display:grid;gap:14px;min-width:0">
                <div class="pg-field">
                    <label for="t-title" class="pg-label">Task</label>
                    <input id="t-title" type="text" wire:model="title" maxlength="255" class="pg-input" style="font-size:16px;font-weight:500" required>
                    @error('title')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-field">
                    <label for="t-desc" class="pg-label">What done looks like</label>
                    <textarea id="t-desc" wire:model="description" rows="4" maxlength="5000" class="pg-textarea" placeholder="Steps, acceptance criteria or anything the person doing it needs to know."></textarea>
                    @error('description')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-form-row pg-form-row--3">
                    <div class="pg-field">
                        <label for="t-status" class="pg-label">Status</label>
                        <select id="t-status" wire:model="status" class="pg-select">
                            @foreach (App\Services\ProjectSummary::STATUSES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="pg-field">
                        <label for="t-priority" class="pg-label">Priority</label>
                        <select id="t-priority" wire:model="priority" class="pg-select">
                            @foreach (App\Services\ProjectWork::PRIORITIES as $p)<option value="{{ $p }}">{{ ucfirst($p) }}</option>@endforeach
                        </select>
                    </div>
                    <div class="pg-field">
                        <label for="t-assignee" class="pg-label">Who does it</label>
                        <select id="t-assignee" wire:model="assignee" class="pg-select">
                            <option value="">Unassigned</option>
                            @foreach ($this->people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                        </select>
                        @error('assignee')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="pg-form-row pg-form-row--3">
                    <div class="pg-field">
                        <label for="t-milestone" class="pg-label">Milestone</label>
                        <select id="t-milestone" wire:model="milestone" class="pg-select">
                            <option value="">None</option>
                            @foreach ($this->milestones as $m)<option value="{{ $m->id }}">{{ $m->title }}</option>@endforeach
                        </select>
                        @error('milestone')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="t-start" class="pg-label">Starts</label>
                        <input id="t-start" type="date" wire:model="startDate" class="pg-input">
                        @error('startDate')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="t-due" class="pg-label">Due</label>
                        <input id="t-due" type="date" wire:model="dueDate" class="pg-input">
                        @error('dueDate')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="pg-form-row pg-form-row--3">
                    <div class="pg-field">
                        <label for="t-estimate" class="pg-label">Estimate, hours</label>
                        <input id="t-estimate" type="number" step="0.25" min="0" wire:model="estimateHours" class="pg-input" placeholder="4">
                        @error('estimateHours')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </fieldset>
            @if ($canEdit)
                <div class="pg-actions" style="justify-content:flex-end;align-items:center;border-top:1px solid var(--pg-rule);padding-top:14px">
                    @if (session('task-saved'))<span class="pg-help" role="status" style="margin:0">{{ session('task-saved') }}</span>@endif
                    <button type="submit" class="pg-btn pg-btn--primary" wire:loading.attr="disabled" wire:target="save">Save changes</button>
                </div>
            @else
                <p class="pg-help" style="margin:0">{{ $project->isArchived() ? 'This project is archived, so its work is read-only.' : 'You have read-only access to this business.' }}</p>
            @endif
        </form>
    </section>

    <aside class="pg-s4" style="display:grid;gap:var(--pg-gap);align-content:start">
        <section class="pg-card" aria-labelledby="check-h">
            <div class="pg-card-head"><h2 id="check-h">Checklist</h2><small>{{ $this->checklist->count() ? $done.' of '.$this->checklist->count().' done' : '' }}</small></div>
            @forelse ($this->checklist as $item)
                <div class="pg-row" style="grid-template-columns:auto minmax(0,1fr) auto;align-items:center" wire:key="item-{{ $item->id }}">
                    <input type="checkbox" class="pg-check" id="item-{{ $item->id }}" @checked($item->done_at) @disabled(! $canEdit) wire:click="toggleItem({{ $item->id }})">
                    <label for="item-{{ $item->id }}" style="{{ $item->done_at ? 'color:var(--pg-muted);text-decoration:line-through' : '' }}">{{ $item->body }}</label>
                    @if ($canEdit)<button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="removeItem({{ $item->id }})" aria-label="Remove {{ $item->body }}">Remove</button>@endif
                </div>
            @empty
                <p class="pg-empty">No checklist.{{ $canEdit ? ' Break the task into steps you can tick off.' : '' }}</p>
            @endforelse
            @if ($canEdit)
                <form wire:submit="addItem" style="display:flex;gap:8px;margin-top:10px">
                    <label for="new-item" class="pg-sr">New checklist item</label>
                    <input id="new-item" type="text" wire:model="newItem" maxlength="255" class="pg-input" placeholder="Add a step">
                    <button type="submit" class="pg-btn pg-btn--secondary">Add</button>
                </form>
                @error('newItem')<p class="pg-error">{{ $message }}</p>@enderror
            @endif
        </section>

        <section class="pg-card" aria-labelledby="facts-h">
            <div class="pg-card-head"><h2 id="facts-h">Record</h2></div>
            <div class="pg-row"><span class="pg-sub">Added</span><span>{{ $task->created_at?->format('j M Y') ?? '–' }}</span></div>
            <div class="pg-row"><span class="pg-sub">Finished</span><span>{{ $task->completed_at?->format('j M Y, H:i') ?? 'Not yet' }}</span></div>
            <div class="pg-row"><span class="pg-sub">Estimate</span><span>{{ $task->estimateLabel() ?? 'None' }}</span></div>
            @can('delete', $task)
                <div style="margin-top:12px">
                    @if ($confirmingDelete)
                        <p class="pg-help" style="margin:0 0 8px">Delete this task, its checklist and its comments? This cannot be undone.</p>
                        <div class="pg-actions">
                            <button type="button" class="pg-btn pg-btn--danger pg-btn--sm" wire:click="delete">Delete task</button>
                            <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('confirmingDelete', false)">Keep it</button>
                        </div>
                    @else
                        <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('confirmingDelete', true)">Delete task</button>
                    @endif
                </div>
            @endcan
        </section>
    </aside>

    <section class="pg-card pg-s8" aria-labelledby="task-talk-h">
        <div class="pg-card-head"><h2 id="task-talk-h">Comments on this task</h2></div>
        <livewire:projects.project-comments :project="$project" :task="$task" :key="'task-comments-'.$task->id" />
    </section>
</div>
