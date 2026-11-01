{{-- The facts of a status report: live for a draft, frozen for a published one. Needs $f, $project. --}}
@php
    $d = fn (?string $date, string $fmt = 'j M Y') => $date ? Illuminate\Support\Carbon::parse($date)->format($fmt) : null;
    $band = ['low' => '', 'medium' => 'pg-pill--warning', 'high' => 'pg-pill--negative', 'critical' => 'pg-pill--negative pg-pill--solid'];
    $s = $f['schedule'];
    $w = $f['work'];
    $plural = fn (int $n, string $word) => $n.' '.Str::plural($word, $n);
@endphp
<div class="pg-facts">
    <section aria-labelledby="fx-when">
        <h3 id="fx-when">When we land</h3>
        <dl class="pg-kv">
            <dt>Due</dt><dd>{{ $d($s['due']) ?? 'No due date set' }}</dd>
            <dt>Tasks as dated finish</dt><dd class="{{ $s['days_late'] > 0 ? 'pg-neg' : '' }}">{{ $d($s['forecast']) ?? 'No task has a date' }}{{ $s['days_late'] > 0 ? ', '.$plural($s['days_late'], 'day').' late' : '' }} <span class="pg-muted">(forecast)</span></dd>
            @if ($s['baseline'])<dt>Baseline {{ $s['baseline'] }}</dt><dd>Due {{ $d($s['baseline_due']) ?? 'no date' }}</dd>@endif
            <dt>Critical path</dt><dd>{{ $plural($s['critical'], 'open task') }}{{ $s['conflicts'] ? ', '.$plural($s['conflicts'], 'broken dependency') : '' }}</dd>
        </dl>
    </section>
    <section aria-labelledby="fx-work">
        <h3 id="fx-work">Work</h3>
        <dl class="pg-kv">
            <dt>Done</dt><dd>{{ $w['done'] }} of {{ $plural($w['total'], 'task') }}, {{ $w['done_in_period'] }} in this period</dd>
            <dt>In the way</dt><dd class="{{ $w['overdue'] ? 'pg-neg' : '' }}">{{ $w['overdue'] }} overdue, {{ $w['blocked'] }} blocked</dd>
            @if ($f['milestone'])<dt>Next milestone</dt><dd class="{{ $f['milestone']['late'] ? 'pg-neg' : '' }}">{{ $f['milestone']['title'] }}, {{ $f['milestone']['late'] ? 'was due' : 'due' }} {{ $d($f['milestone']['due'], 'j M') ?? 'no date' }}, {{ $f['milestone']['done'] }} of {{ $f['milestone']['total'] }} done</dd>@endif
        </dl>
    </section>
    <section aria-labelledby="fx-risk">
        <h3 id="fx-risk">Risks and issues</h3>
        <dl class="pg-kv">
            <dt>Risks</dt><dd>{{ $f['risks']['live'] }} live, {{ $f['risks']['serious'] }} high or critical, {{ $f['risks']['escalated'] }} escalated, {{ $f['risks']['new'] }} new</dd>
            <dt>Issues</dt><dd class="{{ $f['issues']['late'] ? 'pg-neg' : '' }}">{{ $f['issues']['open'] }} open, {{ $f['issues']['serious'] }} high or critical, {{ $f['issues']['late'] }} past target, {{ $f['issues']['resolved_in_period'] }} resolved</dd>
        </dl>
        @if ($f['risks']['top'] || $f['issues']['top'])
            <ul class="pg-factlist">
                @foreach ($f['risks']['top'] as $r)<li><span class="pg-id">{{ $r['ref'] }}</span> {{ $r['title'] }} <span class="pg-pill {{ $band[$r['band']] ?? '' }}">{{ ucfirst($r['band']) }} {{ $r['score'] }}</span></li>@endforeach
                @foreach ($f['issues']['top'] as $i)<li><span class="pg-id">{{ $i['ref'] }}</span> {{ $i['title'] }} <span class="pg-pill {{ $band[$i['severity']] ?? '' }}">{{ ucfirst($i['severity']) }} issue</span></li>@endforeach
            </ul>
        @endif
    </section>
    <section aria-labelledby="fx-change">
        <h3 id="fx-change">Changes and decisions</h3>
        <dl class="pg-kv">
            <dt>Waiting</dt><dd>{{ $plural($f['changes']['waiting'], 'change') }} for approval, {{ $plural($f['decisions']['waiting'], 'decision') }} to make{{ $f['decisions']['overdue'] ? ', '.$f['decisions']['overdue'].' past due' : '' }}</dd>
        </dl>
        @if ($f['changes']['approved_in_period'] || $f['decisions']['made_in_period'])
            <ul class="pg-factlist">
                @foreach ($f['changes']['approved_in_period'] as $c)<li><span class="pg-id">{{ $c['ref'] }}</span> {{ $c['title'] }}: approved{{ $c['days'] ? ', '.($c['days'] > 0 ? '+' : '−').abs($c['days']).' days' : '' }}{{ $c['cents'] ? ', '.App\Support\Money::rand((int) $c['cents'], true) : '' }}</li>@endforeach
                @foreach ($f['decisions']['made_in_period'] as $dd)<li><span class="pg-id">{{ $dd['ref'] }}</span> {{ $dd['title'] }}</li>@endforeach
            </ul>
        @endif
    </section>
    <section aria-labelledby="fx-gate">
        <h3 id="fx-gate">Gates and actions</h3>
        <dl class="pg-kv">
            @if ($f['gates']['next'])<dt>Next gate</dt><dd class="{{ $f['gates']['next']['overdue'] || $f['gates']['next']['status'] === 'held' ? 'pg-neg' : '' }}">{{ $f['gates']['next']['ref'] }} {{ $f['gates']['next']['title'] }}, {{ $f['gates']['next']['met'] }} of {{ $f['gates']['next']['checks'] }} checks met{{ $f['gates']['next']['status'] === 'held' ? ', held' : '' }}{{ $f['gates']['next']['due'] ? ', due '.$d($f['gates']['next']['due'], 'j M') : '' }}</dd>@endif
            @foreach ($f['gates']['decided_in_period'] as $g)<dt>{{ $g['ref'] }}</dt><dd>{{ $g['title'] }}: {{ mb_strtolower(App\Models\ProjectGate::STATUSES[$g['status']] ?? $g['status']) }} in this period</dd>@endforeach
            <dt>Actions</dt><dd class="{{ $f['actions']['late'] ? 'pg-neg' : '' }}">{{ $f['actions']['open'] }} open, {{ $f['actions']['late'] }} late, {{ $f['actions']['done_in_period'] }} done in this period</dd>
        </dl>
    </section>
</div>
