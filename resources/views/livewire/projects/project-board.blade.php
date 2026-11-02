<div>
    @php($canEdit = auth()->user()->can('update', $project))
    @if ($project->status === 'pending_closure_approval')
        <div class="pg-notice" role="status">
            @can('closeProject', $project)
                <span><b>Every milestone is complete.</b> Confirm to close the project, or keep it open with a reason.</span>
                @if ($rejectingClosure)
                    <span style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <label for="closure-reason" class="pg-sr">Why the project stays open</label>
                        <input id="closure-reason" type="text" wire:model="closureRejectReason" placeholder="Why it stays open" class="pg-input" style="width:260px">
                        <button type="button" wire:click="confirmRejectClosure" class="pg-btn pg-btn--secondary pg-btn--sm">Keep it open</button>
                        <button type="button" wire:click="cancelRejectClosure" class="pg-btn pg-btn--ghost pg-btn--sm">Cancel</button>
                    </span>
                @else
                    <span style="display:flex;gap:8px">
                        <button type="button" wire:click="confirmProjectClosure" class="pg-btn pg-btn--primary pg-btn--sm">Close the project</button>
                        <button type="button" wire:click="promptRejectClosure" class="pg-btn pg-btn--secondary pg-btn--sm">Keep it open</button>
                    </span>
                @endif
            @else
                <span><b>Every milestone is complete.</b> Waiting for {{ $project->owner?->name ?? 'the project owner' }} to confirm closing the project.</span>
            @endcan
        </div>
    @endif

    @if ($canEdit)
        <section class="pg-card" style="margin-bottom:12px" aria-labelledby="quick-h">
            <div class="pg-card-head"><h2 id="quick-h">Add a task</h2><small>It goes into Backlog; drag it on from there.</small></div>
            <form wire:submit="addTask" class="pg-quick">
                <div class="pg-field">
                    <label for="task-title" class="pg-label">Task</label>
                    <input id="task-title" type="text" wire:model="newTaskTitle" class="pg-input" maxlength="255" placeholder="Fit telematics to haul truck HT-01" required>
                    @error('newTaskTitle')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-field">
                    <label for="task-milestone" class="pg-label">Milestone</label>
                    <select id="task-milestone" wire:model="newTaskMilestone" class="pg-select">
                        <option value="">None</option>
                        @foreach ($this->milestones as $m)<option value="{{ $m->id }}">{{ $m->title }}</option>@endforeach
                    </select>
                    @error('newTaskMilestone')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-field">
                    <label for="task-due" class="pg-label">Due</label>
                    <input id="task-due" type="date" wire:model="newTaskDue" class="pg-input">
                    @error('newTaskDue')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="pg-btn pg-btn--primary" wire:loading.attr="disabled" wire:target="addTask">Add</button>
            </form>
            <details id="add-milestone" style="margin-top:12px" @if ($this->milestones->isEmpty()) open @endif>
                <summary class="pg-btn pg-btn--ghost pg-btn--sm" style="list-style:none;display:inline-flex">Add a milestone</summary>
                <form wire:submit="addMilestone" class="pg-quick" style="grid-template-columns:minmax(0,2fr) 160px auto;margin-top:10px">
                    <div class="pg-field">
                        <label for="ms-title" class="pg-label">Milestone</label>
                        <input id="ms-title" type="text" wire:model="newMilestoneTitle" class="pg-input" maxlength="255" placeholder="Kathu go-live" required>
                        @error('newMilestoneTitle')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="ms-due" class="pg-label">Due</label>
                        <input id="ms-due" type="date" wire:model="newMilestoneDue" class="pg-input">
                        @error('newMilestoneDue')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="pg-btn pg-btn--secondary" wire:loading.attr="disabled" wire:target="addMilestone">Add milestone</button>
                </form>
            </details>
        </section>
    @else
        <p class="pg-help" style="margin:0 0 12px">You have read-only access to this business, so you can see the board but not change it.</p>
    @endif

    @error('status')<div class="pg-notice" role="alert" style="margin-bottom:12px"><span>{{ $message }}</span></div>@enderror
    <div class="pg-board">
        @foreach (\App\Livewire\Projects\ProjectBoard::COLUMNS as $status => $label)
            <section class="pg-col" aria-label="{{ $label }}"
                 @if ($canEdit)
                 x-data="{ over: false }" x-bind:class="{ 'is-over': over }"
                 x-on:dragover.prevent="over = true" x-on:dragleave="over = false"
                 x-on:drop.prevent="over = false; $wire.moveTask($event.dataTransfer.getData('taskId'), '{{ $status }}')"
                 @endif>
                <div class="pg-col-head">{{ $label }}<span>{{ $this->tasksByStatus[$status]->count() }}</span></div>
                @forelse ($this->tasksByStatus[$status] as $task)
                    @php($late = $task->status !== 'done' && $task->due_date && $task->due_date->isPast() && ! $task->due_date->isToday())
                    <article class="pg-task" wire:key="task-{{ $task->id }}"
                         @if ($canEdit) draggable="true" x-data x-on:dragstart="$event.dataTransfer.setData('taskId', '{{ $task->id }}')" @endif>
                        <a href="{{ route('projects.tasks.show', [$project, $task]) }}" style="text-decoration:none" draggable="false">{{ $task->title }}</a>
                        @if ($task->milestone)<div class="pg-sub" style="font-size:12px;color:var(--pg-muted)">{{ $task->milestone->title }}</div>@endif
                        <div class="pg-task-meta">
                            <span>
                                @if ($late)<span class="pg-pill pg-pill--negative">Due {{ $task->due_date->format('j M') }}</span>
                                @elseif ($task->due_date)<span>Due {{ $task->due_date->format('j M') }}</span>
                                @endif
                                @if ($task->isBlocked())<span class="pg-pill pg-pill--warning" title="Waits for {{ $task->predecessors->where('status', '!=', 'done')->map(fn ($p) => $p->nameFor(auth()->user(), $project->id))->join(', ') }}">Blocked</span>@endif
                                @if (in_array($task->priority, ['urgent', 'high'], true))<span class="pg-pill {{ $task->priority === 'urgent' ? 'pg-pill--warning' : '' }}">{{ ucfirst($task->priority) }}</span>@endif
                            </span>
                            <label for="assign-{{ $task->id }}" class="pg-sr">Who does {{ $task->title }}</label>
                            <select id="assign-{{ $task->id }}" wire:change="assignTask({{ $task->id }}, $event.target.value || null)" x-on:click.stop x-on:mousedown.stop @disabled(! $canEdit)>
                                <option value="" @selected(! $task->assignee_id)>Unassigned</option>
                                @foreach ($this->assignableUsers as $member)
                                    <option value="{{ $member->id }}" @selected($task->assignee_id === $member->id)>{{ $member->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </article>
                @empty
                    <p class="pg-empty" style="font-size:12.5px;padding:4px 2px">{{ $canEdit ? 'Drop a task here.' : 'Nothing here.' }}</p>
                @endforelse
            </section>
        @endforeach
    </div>
</div>
