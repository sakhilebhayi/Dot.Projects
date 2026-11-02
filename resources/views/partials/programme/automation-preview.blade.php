{{-- A rule's dry run. Needs $pv (Automations::preview) and $h, the heading for event rules; dated rules say what they would act on at their next check. --}}
@php $dated = $pv['dated'] ?? false; @endphp
<section class="pg-card pg-auto-preview" aria-labelledby="pv-h" style="margin-top:var(--pg-gap)">
    <div class="pg-card-head"><h2 id="pv-h">{{ $dated ? 'What it would act on at its next check' : $h }}</h2><small>{{ $pv['total'] ? $pv['total'].' '.($dated ? Str::plural('item', $pv['total']) : Str::plural('time', $pv['total'])) : '' }}</small></div>
    @forelse ($pv['items'] as $it)
        <div class="pg-row">
            <div style="min-width:0">
                <div>{{ $it['what'] }}<span class="pg-muted">, {{ $it['project'] }}</span></div>
                <ul class="pg-auto-steps">@foreach ($it['steps'] as $s)<li>{{ ucfirst($s) }}</li>@endforeach</ul>
            </div>
            <span class="pg-sub">{{ $dated ? ($it['done'] ? 'already done' : 'next check') : $it['at']->format('j M').($it['done'] ? ', ran' : '') }}</span>
        </div>
    @empty
        <p class="pg-empty">{{ $dated ? 'Nothing is due for it now.' : 'Nothing in the last 30 days would have set it off.' }}</p>
    @endforelse
    @if ($pv['total'] > count($pv['items']))<p class="pg-help">And {{ $pv['total'] - count($pv['items']) }} more.</p>@endif
    <p class="pg-help">{{ $dated
        ? 'Worked out from what is due now, without doing any of it. Dated rules are checked every hour from 07:00 to 19:00 South African time, and act once for each task, milestone or risk and its date.'
        : 'Worked out from what happened in the last 30 days, without doing any of it. Once a rule has run for a record or an event, it does not run for it again.' }}</p>
</section>
