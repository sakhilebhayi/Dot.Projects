<div>
    @if($blockedMessage)
        <div class="mb-4 p-3 rounded-lg border border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20 text-sm text-red-700 dark:text-red-300 flex items-center justify-between">
            <span>🚫 {{ $blockedMessage }}</span>
            <button wire:click="$set('blockedMessage', null)" class="text-red-400 hover:text-red-600">✕</button>
        </div>
    @endif

    @if($project->status === 'pending_closure_approval')
        <div class="mb-4 p-4 rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20">
            @if(auth()->id() === $project->owner_id)
                <p class="text-sm font-medium text-amber-800 dark:text-amber-300 mb-2">
                    All milestones are complete. Confirm to close this project, or reject to keep it active.
                </p>
                @if($rejectingClosure)
                    <div class="flex items-center gap-2">
                        <input type="text" wire:model="closureRejectReason" placeholder="Reason for rejecting"
                            class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                        <button wire:click="confirmRejectClosure" class="text-xs px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white font-medium">
                            Confirm Reject
                        </button>
                        <button wire:click="cancelRejectClosure" class="text-xs px-3 py-1.5 rounded bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-medium">
                            Cancel
                        </button>
                    </div>
                @else
                    <div class="flex items-center gap-2">
                        <button wire:click="confirmProjectClosure" class="text-xs px-3 py-1.5 rounded bg-green-600 hover:bg-green-700 text-white font-medium">
                            Confirm Closure
                        </button>
                        <button wire:click="promptRejectClosure" class="text-xs px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white font-medium">
                            Reject
                        </button>
                    </div>
                @endif
            @else
                <p class="text-sm font-medium text-amber-800 dark:text-amber-300">
                    All milestones are complete. Awaiting the project owner's confirmation to close this project.
                </p>
            @endif
        </div>
    @endif

    <div class="flex gap-4 overflow-x-auto pb-4">
        @foreach(\App\Livewire\Projects\ProjectBoard::COLUMNS as $status => $label)
            <div class="shrink-0 w-72 bg-gray-100 dark:bg-gray-900 rounded-xl p-3"
                 x-data
                 x-on:dragover.prevent
                 x-on:drop.prevent="$wire.moveTask($event.dataTransfer.getData('taskId'), '{{ $status }}')">

                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $label }}</h3>
                    <span class="text-xs bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-400 rounded-full px-2 py-0.5">
                        {{ $this->tasksByStatus[$status]->count() }}
                    </span>
                </div>

                <div class="space-y-2 min-h-8">
                    @foreach($this->tasksByStatus[$status] as $task)
                        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 shadow-sm cursor-grab active:cursor-grabbing border border-gray-200 dark:border-gray-700"
                             x-data="{ showDeps: false, showTimeLog: false, logMinutes: '', logNote: '' }"
                             draggable="true"
                             x-on:dragstart="$event.dataTransfer.setData('taskId', '{{ $task->id }}')">
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <p class="text-sm text-gray-900 dark:text-white font-medium">{{ $task->title }}</p>
                                @if($task->isBlocked())
                                    <span class="shrink-0 text-xs px-1.5 py-0.5 rounded bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300" title="Blocked by an incomplete dependency">
                                        🔒 Blocked
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs px-1.5 py-0.5 rounded
                                    {{ match($task->priority) {
                                        'urgent' => 'bg-red-100 text-red-700',
                                        'high'   => 'bg-orange-100 text-orange-700',
                                        'medium' => 'bg-yellow-100 text-yellow-700',
                                        'low'    => 'bg-gray-100 text-gray-600',
                                        default  => 'bg-gray-100 text-gray-600',
                                    } }}">
                                    {{ ucfirst($task->priority) }}
                                </span>
                                <select wire:change="assignTask({{ $task->id }}, $event.target.value || null)"
                                        x-on:click.stop x-on:mousedown.stop
                                        class="text-xs bg-transparent text-gray-500 dark:text-gray-400 border-0 max-w-24 focus:ring-0 cursor-pointer">
                                    <option value="" {{ ! $task->assignee_id ? 'selected' : '' }}>Unassigned</option>
                                    @foreach($this->assignableUsers as $teamMember)
                                        <option value="{{ $teamMember->id }}" {{ $task->assignee_id === $teamMember->id ? 'selected' : '' }}>{{ $teamMember->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if($task->milestone)
                                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500 truncate">📍 {{ $task->milestone->title }}</p>
                            @endif

                            <button type="button" x-on:click.stop="showDeps = !showDeps"
                                    class="mt-1 text-xs text-indigo-500 hover:text-indigo-700 dark:text-indigo-400">
                                🔗 {{ $task->dependencies->count() }} {{ Str::plural('dependency', $task->dependencies->count()) }}
                            </button>

                            <div x-show="showDeps" x-on:click.stop x-cloak class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-700 space-y-1.5">
                                @forelse($task->dependencies as $dependency)
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="{{ $dependency->status === 'done' ? 'text-gray-400 line-through' : 'text-gray-600 dark:text-gray-300' }} truncate">
                                            {{ $dependency->title }}
                                        </span>
                                        <button wire:click="removeDependency({{ $task->id }}, {{ $dependency->id }})"
                                                class="text-gray-300 hover:text-red-500 shrink-0 ml-2">✕</button>
                                    </div>
                                @empty
                                    <p class="text-xs text-gray-400">No dependencies.</p>
                                @endforelse

                                <select wire:change="addDependency({{ $task->id }}, $event.target.value)" x-on:mousedown.stop
                                        class="w-full text-xs rounded border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white mt-1">
                                    <option value="">+ Add dependency…</option>
                                    @foreach($this->allTasks as $other)
                                        @if($other->id !== $task->id && ! $task->dependencies->contains($other->id))
                                            <option value="{{ $other->id }}">{{ $other->title }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>

                            <button type="button" x-on:click.stop="showTimeLog = !showTimeLog"
                                    class="mt-1 text-xs text-indigo-500 hover:text-indigo-700 dark:text-indigo-400">
                                ⏱ {{ $task->timeLogs->sum('minutes') }}m logged
                            </button>

                            <div x-show="showTimeLog" x-on:click.stop x-cloak class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-700 space-y-1.5">
                                @foreach($task->timeLogs->sortByDesc('logged_at')->take(3) as $log)
                                    <div class="text-xs text-gray-500 dark:text-gray-400 flex justify-between">
                                        <span class="truncate">{{ $log->user?->name ?? 'Someone' }}{{ $log->note ? ' — '.$log->note : '' }}</span>
                                        <span class="shrink-0 ml-2">{{ $log->minutes }}m</span>
                                    </div>
                                @endforeach

                                <div class="flex items-center gap-1 mt-1">
                                    <input type="number" min="1" placeholder="min" x-model="logMinutes" x-on:mousedown.stop
                                           class="w-16 text-xs rounded border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                    <input type="text" placeholder="note (optional)" x-model="logNote" x-on:mousedown.stop
                                           class="flex-1 min-w-0 text-xs rounded border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                    <button type="button"
                                            x-on:click.stop="if (logMinutes) { $wire.logTime({{ $task->id }}, logMinutes, logNote); logMinutes = ''; logNote = ''; }"
                                            class="text-xs px-2 py-1 rounded bg-indigo-600 hover:bg-indigo-700 text-white shrink-0">
                                        Log
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <script>
        document.addEventListener('livewire:init', () => {
            if (! window.Echo) {
                return; // BROADCAST_CONNECTION isn't reverb (e.g. local "log"/tests) -- nothing to subscribe to.
            }

            window.Echo.private(`project.{{ $project->id }}`)
                .listen('.board.updated', () => {
                    $wire.call('refreshBoard');
                });
        });
    </script>
</div>
