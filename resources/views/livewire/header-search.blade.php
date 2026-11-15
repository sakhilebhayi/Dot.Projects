<x-dot.search wire="query" name="q" label="Search Dot.Projects" placeholder="Find a project or task" hint="Ctrl K" :hint-key="false"
    results-nav enter-first escape-clears autocomplete="off" x-on:focus.once="$wire.touch()" :form-attributes="['wire:submit.prevent' => 'submit', 'data-pg-search' => '']">
    <x-slot:results>
        @if ($touched)
            <div class="dsr-quick">
                @forelse ($groups as $group => $items)
                    <x-dot.search-head>{{ $group }}</x-dot.search-head>
                    @foreach ($items as $item)
                        <x-dot.search-row :href="$item['url']" :label="$item['label']" :tag="$item['sub']" wire:key="hs-{{ $group }}-{{ $loop->index }}-{{ md5($item['url']) }}" />
                    @endforeach
                @empty
                    <p class="dsr-foot" role="status">Nothing you can open matches &ldquo;{{ \Illuminate\Support\Str::limit(trim($query), 60) }}&rdquo;.</p>
                @endforelse
                <p class="dsr-foot">Ctrl K opens commands: start a project, or add what you typed as a task. Only what you can already open is found.</p>
            </div>
        @endif
    </x-slot:results>
</x-dot.search>
