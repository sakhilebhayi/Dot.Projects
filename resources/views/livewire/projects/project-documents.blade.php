<div class="pg-docs">
    @if (! $ready)
        <p class="pg-notice pg-docs-off" role="status"><span>{{ $filesOn ? 'This business is not linked to its InfoDot company yet, so Dot.Files cannot file its documents.' : 'Documents are kept in the business\'s Dot.Files workspace, which is not connected on this server yet.' }}@if ($oversees) <a href="{{ route('integrations.index') }}">See Integrations</a>.@endif</span></p>
    @endif

    @forelse ($docs as $d)
        <div class="pg-row pg-doc" wire:key="doc-{{ $d->id }}">
            <div style="min-width:0">
                <a href="{{ route('projects.documents.download', [$project, $d]) }}">{{ $d->name }}</a>
                <div class="pg-sub">{{ $d->sizeLabel() }}, version {{ $d->version ?? 1 }}{{ ! $task && $d->task ? ', for “'.Str::limit($d->task->title, 40).'”' : '' }}. Added by {{ $d->uploader?->name ?? 'someone' }}, {{ $d->created_at->format('j M Y') }}{{ $d->updated_at->gt($d->created_at) ? '; changed '.$d->updated_at->format('j M') : '' }}.</div>
                @if ($replacing === $d->id)
                    <form wire:submit="replace" class="pg-docs-replace">
                        <label for="rep-{{ $d->id }}" class="pg-sr">New version of {{ $d->name }}</label>
                        <input id="rep-{{ $d->id }}" type="file" wire:model="replacement" class="pg-input">
                        <button type="submit" class="pg-btn pg-btn--secondary pg-btn--sm" wire:loading.attr="disabled" wire:target="replacement,replace">Save as a new version</button>
                        <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('replacing', null)">Cancel</button>
                    </form>
                    @error('replace')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
                    @error('replacement')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
                @endif
            </div>
            <span class="pg-actions pg-doc-acts">
                @if ($d->open_url)<a class="pg-btn pg-btn--ghost pg-btn--sm" href="{{ $d->open_url }}" target="_blank" rel="noopener">Open in Dot.Files</a>@endif
                @if ($canEdit && $ready)
                    <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="startReplace({{ $d->id }})">New version</button>
                    <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="remove({{ $d->id }})" wire:confirm="Move “{{ $d->name }}” to the business's Dot.Files trash? Its owner can restore it there for 30 days.">Remove</button>
                @endif
            </span>
        </div>
    @empty
        <p class="pg-empty">{{ $task ? 'No documents for this task yet.' : 'No documents yet. Plans, drawings, minutes and reports belong here.' }}</p>
    @endforelse

    @if ($canEdit && $ready)
        <form wire:submit="add" class="pg-docs-add">
            <div class="pg-field" style="flex:1 1 240px"><label for="doc-new-{{ $task?->id ?? 'p' }}" class="pg-label">Add a document</label><input id="doc-new-{{ $task?->id ?? 'p' }}" type="file" wire:model="upload" class="pg-input"></div>
            @if (! $task && $tasks->isNotEmpty())
                <div class="pg-field" style="flex:0 1 240px"><label for="doc-task" class="pg-label">For a task <span class="pg-muted">(optional)</span></label>
                    <select id="doc-task" wire:model="taskFor" class="pg-select"><option value="">The whole project</option>@foreach ($tasks as $t)<option value="{{ $t->id }}">{{ Str::limit($t->title, 50) }}</option>@endforeach</select></div>
            @endif
            <button type="submit" class="pg-btn pg-btn--primary" wire:loading.attr="disabled" wire:target="upload,add"><span wire:loading.remove wire:target="add">File it in Dot.Files</span><span wire:loading wire:target="add">Filing…</span></button>
            @if ($project->isPrivate())
                <label class="pg-radio pg-docs-ack"><input type="checkbox" wire:model="acknowledged"> This project is private, but Dot.Files shows documents to whoever may open its folder there. File it anyway.</label>
            @endif
            <p class="pg-help" style="flex-basis:100%">Up to 25 MB: {{ implode(', ', App\Services\Integrations\FilesClient::EXTENSIONS) }}. Filed in the business's Dot.Files workspace, in the folder “Dot.Projects - {{ $project->name }}”.</p>
        </form>
        @error('document')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
        @error('upload')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
    @endif
</div>
