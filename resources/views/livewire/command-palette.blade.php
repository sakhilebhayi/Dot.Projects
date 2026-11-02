<div class="pg-cmd-body">
    <div class="pg-cmd-input">
        <span class="material-symbols-rounded" aria-hidden="true">search</span>
        <input type="text" wire:model.live.debounce.150ms="q" data-cmd-input autocomplete="off" spellcheck="false" maxlength="255"
               placeholder="Find a project, task, document or report, or go somewhere" aria-label="Search or run a command"
               role="combobox" aria-expanded="true" aria-controls="cmd-list" aria-autocomplete="list">
        <kbd class="pg-kbd">Esc</kbd>
    </div>
    @error('q')<p class="pg-error pg-cmd-error" role="alert">{{ $message }}</p>@enderror
    <div id="cmd-list" class="pg-cmd-list" role="listbox" aria-label="Results">
        {{-- Navigation first: Enter after a search opens the best match. Writing (adding a task) comes after the
             results, so it is only done when chosen, or when nothing else matches. --}}
        @if ($canCreate && trim($q) === '')
            <p class="pg-cmd-group" role="presentation">Do</p>
            <a href="{{ route('projects.create') }}" class="pg-cmd-item" role="option" data-cmd-item>
                <span class="material-symbols-rounded" aria-hidden="true">add</span>
                <span class="pg-cmd-what"><b>New project</b><span>blank or from a template</span></span>
            </a>
        @endif
        @forelse ($groups as $group => $items)
            <p class="pg-cmd-group" role="presentation">{{ $group }}</p>
            @foreach ($items as $item)
                <a href="{{ $item['url'] }}" class="pg-cmd-item" role="option" data-cmd-item wire:key="{{ $group }}-{{ $loop->index }}-{{ md5($item['url']) }}">
                    <span class="material-symbols-rounded" aria-hidden="true">{{ $item['icon'] }}</span>
                    <span class="pg-cmd-what"><b>{{ $item['label'] }}</b>@if ($item['sub'])<span>{{ $item['sub'] }}</span>@endif</span>
                </a>
            @endforeach
        @empty
            @if ($open)
                <p class="pg-cmd-none">Nothing you can open matches “{{ Str::limit(trim($q), 60) }}”.</p>
            @endif
        @endforelse
        @if ($canAdd)
            <p class="pg-cmd-group" role="presentation">Do</p>
            <button type="button" class="pg-cmd-item" role="option" data-cmd-item wire:click="addTask">
                <span class="material-symbols-rounded" aria-hidden="true">add_task</span>
                <span class="pg-cmd-what"><b>Add “{{ Str::limit(trim($q), 60) }}” as a task</b><span>in {{ $here->name }}, at the end of the backlog</span></span>
            </button>
        @endif
    </div>
    <p class="pg-cmd-help"><kbd class="pg-kbd">↑</kbd> <kbd class="pg-kbd">↓</kbd> to move, <kbd class="pg-kbd">Enter</kbd> to open. Only what you can already open is found.</p>
</div>
