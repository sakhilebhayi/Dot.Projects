{{-- A rule's dry run. Needs $pv (Automations::preview) and $h, the heading. --}}
<section class="pg-card pg-auto-preview" aria-labelledby="pv-h" style="margin-top:var(--pg-gap)">
    <div class="pg-card-head"><h2 id="pv-h">{{ $h }}</h2><small>{{ $pv['total'] ? $pv['total'].' '.Str::plural('time', $pv['total']) : '' }}</small></div>
    @forelse ($pv['items'] as $it)
        <div class="pg-row">
            <div style="min-width:0">
                <div>{{ $it['what'] }}<span class="pg-muted">, {{ $it['project'] }}</span></div>
                <ul class="pg-auto-steps">@foreach ($it['steps'] as $s)<li>{{ ucfirst($s) }}</li>@endforeach</ul>
            </div>
            <span class="pg-sub">{{ $it['at']->format('j M') }}{{ $it['done'] ? ', ran' : '' }}</span>
        </div>
    @empty
        <p class="pg-empty">Nothing in the last 30 days would have set it off.</p>
    @endforelse
    <p class="pg-help">Worked out from what happened in the last 30 days, without doing any of it. Once a rule has run for a record or an event, it does not run for it again.</p>
</section>
