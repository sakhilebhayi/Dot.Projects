{{-- What a template adds (R-34 P13a): its phases drawn to scale in days, the first tasks of each, then gates,
     risks, budget lines and labels in sentences. Needs $s (a template structure). --}}
@php
    $phases = $s['phases'] ?? [];
    $tasks = collect($s['tasks'] ?? []);
    $total = max(1, (int) ($s['days'] ?? collect($phases)->max('due') ?? 0));
    $prevDue = 0;
    $length = fn (int $days) => $days >= 14 ? 'about '.round($days / 7).' weeks' : $days.' '.Str::plural('day', $days);
    // "A, B and C" for three or fewer; "A, B, C and 4 more" beyond.
    $some = fn ($list) => $list->count() > 3 ? $list->take(3)->pluck('title')->join(', ').' and '.($list->count() - 3).' more' : $list->pluck('title')->join(', ', ' and ');
@endphp
@if ($phases !== [])
    <ol class="pg-tphases">
        @foreach ($phases as $i => $p)
            @php
                $mine = $tasks->where('phase', $i);
                $from = (int) ($mine->min('start') ?? $prevDue);
                $to = (int) ($p['due'] ?? $mine->max('due') ?? $from);
                $prevDue = $to;
                $dated = isset($p['due']);
            @endphp
            <li>
                <div class="pg-tphase-head">
                    <b>{{ $p['title'] }}</b>
                    <span>{{ $mine->count() }} {{ Str::plural('task', $mine->count()) }}@if ($dated && $to > $from), {{ $length($to - $from) }}@endif</span>
                </div>
                @if ($dated)
                    <span class="pg-tbar" aria-hidden="true"><i style="left:{{ round($from / $total * 100, 2) }}%;width:{{ max(1.5, round(($to - $from) / $total * 100, 2)) }}%"></i></span>
                @endif
                @if ($mine->isNotEmpty())
                    <p class="pg-tphase-tasks">{{ $some($mine) }}.</p>
                @endif
            </li>
        @endforeach
    </ol>
@elseif ($tasks->isNotEmpty())
    <p class="pg-tphase-tasks">{{ $tasks->count() }} {{ Str::plural('task', $tasks->count()) }} with no phases: {{ $some($tasks) }}.</p>
@else
    <p class="pg-empty">An empty structure: the project starts blank.</p>
@endif
<dl class="pg-kv pg-tadds">
    @if (! empty($s['gates']))
        <dt>Gates</dt><dd>{{ collect($s['gates'])->map(fn ($g) => $g['title'].' ('.count($g['checks'] ?? []).' '.Str::plural('check', count($g['checks'] ?? [])).')')->join(', ', ' and ') }}</dd>
    @endif
    @if (! empty($s['risks']))
        <dt>Risks to start with</dt><dd>{{ collect($s['risks'])->pluck('title')->join('; ') }}</dd>
    @endif
    @if (! empty($s['budget']))
        <dt>Budget lines</dt><dd>{{ collect($s['budget'])->pluck('name')->join(', ', ' and ') }}, with no amounts</dd>
    @endif
    @if (! empty($s['labels']))
        <dt>Labels</dt><dd>{{ collect($s['labels'])->join(', ', ' and ') }}</dd>
    @endif
</dl>
