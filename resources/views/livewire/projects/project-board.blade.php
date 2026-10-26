<div>
    @php($canEdit = auth()->user()->can('update', $project))
    @if($project->status === 'pending_closure_approval')
        <div class="mb-4 p-4 rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20">
            @can('closeProject', $project)
                <p class="text-sm font-medium text-amber-800 dark:text-amber-300 mb-2">
                    All milestones are complete. Confirm to close this project, or reject to keep it active.
                </p>
                @if($rejectingClosure)
                    <div class="flex items-center gap-2">
                        <input aria-label="Reason for rejecting" type="text" wire:model="closureRejectReason" placeholder="Reason for rejecting"
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
            @endcan
        </div>
    @endif

    <div class="flex gap-4 overflow-x-auto pb-4">
        @foreach(\App\Livewire\Projects\ProjectBoard::COLUMNS as $status => $label)
            <div class="shrink-0 w-72 bg-gray-100 dark:bg-gray-900 rounded-xl p-3"
                 @if($canEdit)
                 x-data
                 x-on:dragover.prevent
                 x-on:drop.prevent="$wire.moveTask($event.dataTransfer.getData('taskId'), '{{ $status }}')"
                 @endif>

                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $label }}</h3>
                    <span class="text-xs bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-400 rounded-full px-2 py-0.5">
                        {{ $this->tasksByStatus[$status]->count() }}
                    </span>
                </div>

                <div class="space-y-2 min-h-8">
                    @foreach($this->tasksByStatus[$status] as $task)
                        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 shadow-sm {{ $canEdit ? 'cursor-grab active:cursor-grabbing' : '' }} border border-gray-200 dark:border-gray-700"
                             @if($canEdit)
                             draggable="true"
                             x-on:dragstart="$event.dataTransfer.setData('taskId', '{{ $task->id }}')"
                             @endif>
                            <p class="text-sm text-gray-900 dark:text-white font-medium mb-2">{{ $task->title }}</p>
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
                                        @disabled(! $canEdit)
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
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
