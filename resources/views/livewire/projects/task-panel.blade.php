<div class="pg-grid">
    @php $canEdit = auth()->user()->can('update', $task); @endphp
    @php $done = $this->checklist->whereNotNull('done_at')->count(); @endphp
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
                    @error('status')<span class="pg-error" role="alert" style="margin:0">{{ $message }}</span>@enderror
                    @if (session('task-saved'))<span class="pg-help" role="status" style="margin:0">{{ session('task-saved') }}</span>@endif
                    <button type="submit" class="pg-btn pg-btn--primary" wire:loading.attr="disabled" wire:target="save">Save changes</button>
                </div>
            @else
                <p class="pg-help" style="margin:0">{{ $project->isArchived() ? 'This project is archived, so its work is read-only.' : 'You have read-only access to this business.' }}</p>
            @endif
        </form>
    </section>

    <aside class="pg-s4" style="display:grid;gap:var(--pg-gap);align-content:start">
        @php
            $t = $this->time;
            $est = $task->estimate_minutes;
            $fmt = fn (int $m) => App\Support\Duration::format($m);
            $mineRunning = $running && $running->task_id === $task->id;
        @endphp
        <section class="pg-card" aria-labelledby="time-h">
            <div class="pg-card-head"><h2 id="time-h">Time</h2><small>{{ $est ? $fmt($t['total']).' of '.$fmt($est).' estimated' : $fmt($t['total']).' logged' }}</small></div>
            @if ($est)
                <div class="pg-meter" role="img" aria-label="{{ $fmt($t['total']) }} logged against {{ $fmt($est) }} estimated"><i style="width:{{ min(100, (int) round($t['total'] / max(1, $est) * 100)) }}%" class="{{ $t['total'] > $est ? 'is-over' : '' }}"></i></div>
                @if ($t['total'] > $est)<p class="pg-help pg-neg" style="margin:6px 0 0">{{ $fmt($t['total'] - $est) }} over the estimate.</p>@endif
            @endif
            <p class="pg-help" style="margin:6px 0 0">You: {{ $fmt($t['mine']) }}.</p>
            @if ($t['people'] && $t['people']->count() > 1)
                <dl class="pg-kv" style="margin-top:8px">@foreach ($t['people'] as $p)<dt>{{ $p['name'] }}</dt><dd>{{ $fmt($p['minutes']) }}</dd>@endforeach</dl>
            @endif
            @if ($mineRunning)
                <div class="pg-notice pg-timer" style="margin:10px 0 0" wire:poll.60s><span>Timer running since {{ $running->running_since->format('H:i') }}: {{ $fmt($running->elapsed()) }}</span><button type="button" class="pg-btn pg-btn--primary pg-btn--sm" wire:click="stopTimer">Stop</button></div>
            @elseif ($canEdit)
                <form wire:submit="logTime" class="pg-form" style="gap:8px;margin-top:10px">
                    <div style="display:flex;gap:8px">
                        <label for="tt-hours" class="pg-sr">Time spent today</label>
                        <input id="tt-hours" type="text" inputmode="decimal" wire:model="logHours" class="pg-input" placeholder="1:30 today" style="flex:1">
                        <button type="submit" class="pg-btn pg-btn--secondary">Log</button>
                    </div>
                    <label for="tt-note" class="pg-sr">What it was for</label>
                    <input id="tt-note" type="text" wire:model="logNote" maxlength="500" class="pg-input" placeholder="What it was for (optional)">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                        <label class="pg-radio"><input type="checkbox" wire:model="logBillable"> Billable</label>
                        <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="startTimer">{{ $running ? 'Switch the timer here' : 'Start the timer' }}</button>
                    </div>
                    @error('minutes')<p class="pg-error">{{ $message }}</p>@enderror
                    @error('work_date')<p class="pg-error">{{ $message }}</p>@enderror
                    @error('project')<p class="pg-error">{{ $message }}</p>@enderror
                    <p class="pg-help" style="margin:0">Other days and the week's timesheet are on <a href="{{ route('time.index') }}">your time</a>.</p>
                </form>
            @endif
        </section>
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

        <section class="pg-card" aria-labelledby="dep-h">
            <div class="pg-card-head"><h2 id="dep-h">Waits for</h2><small>{{ $task->isBlocked() ? 'Blocked' : '' }}</small></div>
            @forelse ($this->predecessors as $p)
                <div class="pg-row" wire:key="pred-{{ $p->id }}">
                    <div>
                        @if ($this->places[$p->id]['url'])<a href="{{ $this->places[$p->id]['url'] }}">{{ $p->title }}</a>@else<span class="pg-dep-hidden">A task in a project you cannot see</span>@endif
                        <div class="pg-sub">@unless ($this->places[$p->id]['here'])<span class="pg-dep-where">{{ $this->places[$p->id]['project'] ? 'In '.$this->places[$p->id]['project'] : 'Another project' }}</span>, @endunless{{ App\Services\ProjectSummary::STATUSES[$p->status] ?? $p->status }}@if (! $this->places[$p->id]['here'] && $p->due_date && $this->places[$p->id]['project']), due {{ $p->due_date->format('j M') }}@endif</div>
                    </div>
                    <span style="display:flex;gap:6px;align-items:center">
                        <span class="pg-pill {{ $p->status === 'done' ? 'pg-pill--positive' : 'pg-pill--warning' }}">{{ $p->status === 'done' ? 'Done' : 'Not done' }}</span>
                        @if ($canEdit)<button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="removeDependency({{ $p->id }})" aria-label="Stop waiting for {{ $this->places[$p->id]['url'] ? $p->title : 'that task' }}">Remove</button>@endif
                    </span>
                </div>
            @empty
                <p class="pg-empty">Nothing. It can start any time.</p>
            @endforelse
            @if ($canEdit)
                <form wire:submit="addDependency" class="pg-dep-form">
                    @if ($this->otherProjects->isNotEmpty())
                        <label for="dep-project" class="pg-sr">Project the task is in</label>
                        <select id="dep-project" wire:model.live="depProject" class="pg-select">
                            <option value="">This project</option>
                            @foreach ($this->otherProjects as $o)<option value="{{ $o->id }}">{{ Str::limit($o->name, 48) }}</option>@endforeach
                        </select>
                    @endif
                    <label for="dep-new" class="pg-sr">Task this one waits for</label>
                    <select id="dep-new" wire:model="newDependency" class="pg-select" @disabled($this->candidates->isEmpty())>
                        <option value="">{{ $this->candidates->isEmpty() ? 'No tasks to choose from' : 'Choose a task to wait for' }}</option>
                        @foreach ($this->candidates as $c)<option value="{{ $c->id }}">{{ Str::limit($c->title, 60) }}</option>@endforeach
                    </select>
                    <button type="submit" class="pg-btn pg-btn--secondary" @disabled($this->candidates->isEmpty())>Add</button>
                </form>
                @if ($depProject !== '')<p class="pg-empty pg-dep-note">Its owner and managers are told that work here waits on it.</p>@endif
                @error('dependency')<p class="pg-error">{{ $message }}</p>@enderror
                @error('newDependency')<p class="pg-error">{{ $message }}</p>@enderror
            @endif
            @if ($this->successors->isNotEmpty())
                <div class="pg-card-head" style="margin-top:14px"><h2>Holds up</h2><small>{{ $this->successors->count() }}</small></div>
                @foreach ($this->successors as $n)
                    <div class="pg-row" wire:key="succ-{{ $n->id }}">
                        <div>
                            @if ($this->places[$n->id]['url'])<a href="{{ $this->places[$n->id]['url'] }}">{{ $n->title }}</a>@else<span class="pg-dep-hidden">A task in a project you cannot see</span>@endif
                            @unless ($this->places[$n->id]['here'])<div class="pg-sub"><span class="pg-dep-where">{{ $this->places[$n->id]['project'] ? 'In '.$this->places[$n->id]['project'] : 'Another project' }}</span></div>@endunless
                        </div>
                        <span class="pg-sub">{{ App\Services\ProjectSummary::STATUSES[$n->status] ?? $n->status }}</span>
                    </div>
                @endforeach
            @endif
        </section>

        <section class="pg-card" aria-labelledby="sub-h">
            <div class="pg-card-head"><h2 id="sub-h">{{ $task->parent_id ? 'Part of' : 'Subtasks' }}</h2><small>{{ $task->parent_id ? '' : ($this->subtasks->count() ? $this->subtasks->where('status', 'done')->count().' of '.$this->subtasks->count().' done' : '') }}</small></div>
            @if ($task->parent_id)
                <p style="margin:0"><a href="{{ route('projects.tasks.show', [$project, $task->parent_id]) }}">{{ $task->parent?->title }}</a></p>
            @else
                @forelse ($this->subtasks as $sub)
                    <div class="pg-row" wire:key="sub-{{ $sub->id }}"><a href="{{ route('projects.tasks.show', [$project, $sub]) }}" style="{{ $sub->status === 'done' ? 'color:var(--pg-muted)' : '' }}">{{ $sub->title }}</a><span class="pg-sub">{{ App\Services\ProjectSummary::STATUSES[$sub->status] ?? $sub->status }}</span></div>
                @empty
                    <p class="pg-empty">None. Split big work into tasks of their own.</p>
                @endforelse
                @if ($canEdit)
                    <form wire:submit="addSubtask" style="display:flex;gap:8px;margin-top:10px">
                        <label for="sub-new" class="pg-sr">New subtask</label>
                        <input id="sub-new" type="text" wire:model="newSubtask" maxlength="255" class="pg-input" placeholder="Add a subtask">
                        <button type="submit" class="pg-btn pg-btn--secondary">Add</button>
                    </form>
                    @error('newSubtask')<p class="pg-error">{{ $message }}</p>@enderror
                    @error('subtask')<p class="pg-error">{{ $message }}</p>@enderror
                @endif
            @endif
        </section>

        <section class="pg-card" aria-labelledby="lab-h">
            <div class="pg-card-head"><h2 id="lab-h">Labels</h2></div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                @forelse ($this->labels as $label)
                    <span class="pg-pill" wire:key="lab-{{ $label->id }}" style="display:inline-flex;gap:4px;align-items:center">{{ $label->name }}@if ($canEdit)<button type="button" wire:click="removeLabel({{ $label->id }})" aria-label="Remove label {{ $label->name }}" style="border:0;background:none;padding:0 0 0 2px;cursor:pointer;color:inherit;font:inherit">×</button>@endif</span>
                @empty
                    <p class="pg-empty">No labels.</p>
                @endforelse
            </div>
            @if ($canEdit)
                <form wire:submit="addLabel" style="display:flex;gap:8px;margin-top:10px">
                    <label for="lab-new" class="pg-sr">New label</label>
                    <input id="lab-new" type="text" wire:model="newLabel" maxlength="40" class="pg-input" placeholder="Customs, Kathu, Safety" list="project-labels">
                    <datalist id="project-labels">@foreach ($project->labels()->get() as $l)<option value="{{ $l->name }}"></option>@endforeach</datalist>
                    <button type="submit" class="pg-btn pg-btn--secondary">Add</button>
                </form>
                @error('label')<p class="pg-error">{{ $message }}</p>@enderror
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
