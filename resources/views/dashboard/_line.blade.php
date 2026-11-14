{{-- The date line of one project: its dated milestones, today, and its due date (the R-50 line, drawn small for the panel). --}}
@php
    $dated = $project->milestones;
    if ($dated->isEmpty()) { $none = true; } else {
        $none = false;
        $from = collect([$project->start_date, $dated->min('due_date'), $today, $project->due_date])->filter()->min()->copy()->startOfMonth();
        $to = collect([$project->due_date, $dated->max('due_date'), $today])->filter()->max()->copy()->endOfMonth();
        $span = max(1, $from->diffInDays($to));
        $at = fn ($d) => round($from->diffInDays($d) / $span * 100, 3);
        $months = []; for ($m = $from->copy(); $m->lte($to); $m->addMonth()) { $months[] = $m->copy(); }
        $step = (int) ceil(count($months) / 6);
        $prev = null;
    }
@endphp
@if ($none)
    <p class="pgd-lnote">No milestone has a date, so there is no line to draw yet.</p>
@else
    <div class="pg-land-line" role="img" aria-label="Milestones of {{ $project->name }} on a date line from {{ $from->format('j M Y') }} to {{ $to->format('j M Y') }}">
        @foreach ($months as $i => $m)<span class="tick" style="left:{{ $at($m) }}%">@if ($i % $step === 0)<span>{{ $m->format($m->month === 1 ? 'M Y' : 'M') }}</span>@endif</span>@endforeach
        <span class="axis"></span>
        <span class="today {{ $at($today) > 80 ? 'end' : '' }}" style="left:{{ $at($today) }}%"><span>Today</span></span>
        @foreach ($dated as $m)
            @php
                $late = $m->status !== 'completed' && $m->due_date->lt($today);
                $low = $prev && $prev->diffInDays($m->due_date) < 28;
                $prev = $low ? null : $m->due_date;
                $x = $at($m->due_date);
            @endphp
            <span class="ms {{ $m->status === 'completed' ? 'done' : ($late ? 'late' : '') }}" style="left:{{ $x }}%" title="{{ $m->title }}, {{ $m->due_date->format('j M Y') }}"></span>
            <span class="lbl {{ $low ? 'low' : '' }} {{ $x > 82 ? 'end' : '' }} {{ $x < 8 ? 'first' : '' }}" style="left:{{ $x }}%"><span class="t">{{ Str::limit($m->title, 24) }}</span><small class="{{ $late ? 'late' : '' }}">{{ $m->due_date->format('j M') }}</small></span>
        @endforeach
    </div>
    <p class="pgd-lnote">Milestones on their planned dates; filled dots are done, red ones are past due.</p>
@endif
