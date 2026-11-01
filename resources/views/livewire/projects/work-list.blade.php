<div>
    @php
        $byMilestone = $this->tasks->groupBy(fn ($t) => (int) $t->milestone_id);
        $groups = $this->milestones->map(fn ($m) => ['milestone' => $m, 'tasks' => $byMilestone->get($m->id, collect())]);
        if ($byMilestone->has(0) || ! $this->filtered()) { $groups->push(['milestone' => null, 'tasks' => $byMilestone->get(0, collect())]); }
        if ($this->filtered()) { $groups = $groups->filter(fn ($g) => $g['tasks']->isNotEmpty()); }
        $priorityPill = ['urgent' => 'pg-pill--warning', 'high' => '', 'medium' => '', 'low' => ''];
    @endphp

    <form class="pg-filters" wire:submit.prevent role="search" aria-label="Filter the work">
        <label for="w-search" class="pg-sr">Find a task</label>
        <input id="w-search" type="search" wire:model.live.debounce.300ms="search" class="pg-input" placeholder="Find a task by name">
        <label for="w-status" class="pg-sr">Status</label>
        <select id="w-status" wire:model.live="status" class="pg-select">
            <option value="">Any status</option>
            <option value="open">Not done</option>
            @foreach ($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <label for="w-who" class="pg-sr">Who</label>
        <select id="w-who" wire:model.live="who" class="pg-select">
            <option value="">Anyone</option>
            <option value="none">Unassigned</option>
            @foreach ($this->people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
        </select>
        <label class="pg-chip" for="w-late" style="cursor:pointer"><input id="w-late" type="checkbox" class="pg-check" wire:model.live="lateOnly"> Late only</label>
        @if ($this->filtered())<button type="button" class="pg-btn pg-btn--ghost" wire:click="clearFilters">Clear</button>@endif
        <span class="pg-help" style="margin:0 0 0 auto" role="status">{{ $this->tasks->count() }} {{ Str::plural('task', $this->tasks->count()) }}{{ $this->filtered() ? ' match' : '' }}</span>
    </form>

    @forelse ($groups as $g)
        @php($m = $g['milestone'])
        <section class="pg-table-wrap" style="margin-bottom:var(--pg-gap)" aria-label="{{ $m?->title ?? 'No milestone' }}" wire:key="group-{{ $m?->id ?? 'none' }}">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 16px 6px;flex-wrap:wrap">
                @if ($m && $editingMilestone === $m->id)
                    <form wire:submit="saveMilestone" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex:1">
                        <label for="ms-edit-title" class="pg-sr">Milestone</label>
                        <input id="ms-edit-title" type="text" wire:model="milestoneTitle" maxlength="255" class="pg-input" style="max-width:320px" required>
                        <label for="ms-edit-due" class="pg-sr">Due</label>
                        <input id="ms-edit-due" type="date" wire:model="milestoneDue" class="pg-input" style="max-width:170px">
                        <button type="submit" class="pg-btn pg-btn--primary pg-btn--sm">Save</button>
                        <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('editingMilestone', null)">Cancel</button>
                        @error('milestoneTitle')<p class="pg-error" style="width:100%">{{ $message }}</p>@enderror
                    </form>
                @else
                    <div>
                        <h2 style="font-size:14px;font-weight:600;margin:0;display:inline">{{ $m?->title ?? 'No milestone' }}</h2>
                        @if ($m)
                            @php($late = $m->status !== 'completed' && $m->due_date && $m->due_date->lt($today))
                            <span class="pg-sub {{ $late ? 'pg-neg' : '' }}" style="margin-left:8px">{{ $m->due_date ? ($late ? 'Was due ' : 'Due ').$m->due_date->format('j M Y') : 'No date' }}</span>
                        @endif
                    </div>
                    @if ($m && $canEdit)
                        <div class="pg-actions">
                            <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="editMilestone({{ $m->id }})">Edit</button>
                            @unless ($loop->first)<button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="moveMilestone({{ $m->id }}, 'up')" aria-label="Move {{ $m->title }} up">Up</button>@endunless
                            @if (! $loop->last && $groups->values()->get($loop->index + 1)['milestone'])<button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="moveMilestone({{ $m->id }}, 'down')" aria-label="Move {{ $m->title }} down">Down</button>@endif
                            @if ($deletingMilestone === $m->id)
                                <span class="pg-help" style="margin:0">Its tasks stay, with no milestone.</span>
                                <button type="button" class="pg-btn pg-btn--danger pg-btn--sm" wire:click="deleteMilestone">Delete milestone</button>
                                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('deletingMilestone', null)">Keep it</button>
                            @else
                                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('deletingMilestone', {{ $m->id }})">Delete</button>
                            @endif
                        </div>
                    @endif
                @endif
            </div>
            @if ($g['tasks']->isEmpty())
                <p class="pg-empty" style="padding:4px 16px 14px">No tasks{{ $canEdit ? '. Add them on the board.' : '.' }}</p>
            @else
                <table class="pg-table pg-table--work">
                    <thead>
                        <tr><th scope="col">Task</th><th scope="col">Status</th><th scope="col">Who</th><th scope="col">Priority</th><th scope="col" class="r">Estimate</th><th scope="col" class="r">Due</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($g['tasks'] as $task)
                            @php($late = $task->status !== 'done' && $task->due_date && $task->due_date->lt($today))
                            @php($items = $task->checklist)
                            <tr wire:key="task-{{ $task->id }}">
                                <td class="pg-name"><a href="{{ route('projects.tasks.show', [$project, $task]) }}" style="{{ $task->status === 'done' ? 'color:var(--pg-muted)' : '' }}">{{ $task->title }}</a>@if ($items->isNotEmpty())<span class="pg-sub">Checklist {{ $items->whereNotNull('done_at')->count() }} of {{ $items->count() }}</span>@endif</td>
                                <td data-label="Status">
                                    @if ($canEdit)
                                        <label for="st-{{ $task->id }}" class="pg-sr">Status of {{ $task->title }}</label>
                                        <select id="st-{{ $task->id }}" class="pg-select" style="min-height:30px;padding:3px 8px;width:auto;position:relative;z-index:1" wire:change="setStatus({{ $task->id }}, $event.target.value)">
                                            @foreach ($statuses as $value => $label)<option value="{{ $value }}" @selected($task->status === $value)>{{ $label }}</option>@endforeach
                                        </select>
                                    @else
                                        {{ $statuses[$task->status] ?? $task->status }}
                                    @endif
                                </td>
                                <td data-label="Who">
                                    @if ($canEdit)
                                        <label for="who-{{ $task->id }}" class="pg-sr">Who does {{ $task->title }}</label>
                                        <select id="who-{{ $task->id }}" class="pg-select" style="min-height:30px;padding:3px 8px;width:auto;max-width:180px;position:relative;z-index:1" wire:change="setAssignee({{ $task->id }}, $event.target.value || null)">
                                            <option value="" @selected(! $task->assignee_id)>Unassigned</option>
                                            @foreach ($this->people as $person)<option value="{{ $person->id }}" @selected($task->assignee_id === $person->id)>{{ $person->name }}</option>@endforeach
                                        </select>
                                    @else
                                        {{ $task->assignee?->name ?? 'Unassigned' }}
                                    @endif
                                </td>
                                <td data-label="Priority"><span class="pg-pill {{ $priorityPill[$task->priority] ?? '' }}">{{ ucfirst($task->priority) }}</span></td>
                                <td data-label="Estimate" class="r {{ $task->estimate_minutes === null ? 'pg-muted' : '' }}">{{ $task->estimateLabel() ?? '–' }}</td>
                                <td data-label="Due" class="r {{ $late ? 'pg-neg' : '' }}">{{ $task->due_date?->format('j M') ?? '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @empty
        <div class="pg-card"><p class="pg-empty">{{ $this->filtered() ? 'No task matches these filters.' : 'No work yet. Add milestones and tasks on the board.' }}</p></div>
    @endforelse
</div>
