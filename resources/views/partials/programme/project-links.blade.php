{{-- Projects that wait on each other. Needs $links (PortfolioOverview::links) and $k, a wire:key prefix. --}}
@forelse ($links['pairs'] as $ln)
    <div class="pg-row" wire:key="{{ $k }}-{{ $ln['waiting']->id }}-{{ $ln['on']->id }}">
        <div style="min-width:0"><a href="{{ route('projects.show', $ln['waiting']) }}">{{ $ln['waiting']->name }}</a> waits on <a href="{{ route('projects.show', $ln['on']) }}">{{ $ln['on']->name }}</a>
            <div class="pg-sub">{{ $ln['links'] }} linked {{ Str::plural('task', $ln['links']) }}, {{ $ln['open'] ? $ln['open'].' still held up' : 'none held up' }}@if ($ln['conflicts'])<span class="pg-neg">, {{ $ln['conflicts'] }} {{ $ln['conflicts'] === 1 ? 'starts' : 'start' }} before the work {{ $ln['conflicts'] === 1 ? 'it waits' : 'they wait' }} for is due</span>@endif</div>
        </div>
    </div>
@empty
    <p class="pg-empty">No project here waits on another. Link tasks across projects from a task's “Waits for”.</p>
@endforelse
@if ($links['outside'])<p class="pg-help">{{ $links['outside'] }} {{ Str::plural('link', $links['outside']) }} {{ $links['outside'] === 1 ? 'runs' : 'run' }} to projects outside this view.</p>@endif
