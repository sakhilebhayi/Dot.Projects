{{-- Major risks across projects. Needs $risks (PortfolioOverview::majorRisks) and $k, a wire:key prefix. --}}
@forelse ($risks['items'] as $mr)
    <div class="pg-row" wire:key="{{ $k }}-{{ $mr['risk']->id }}">
        <div style="min-width:0"><a href="{{ route('projects.risks.show', [$mr['project'], $mr['risk']]) }}">{{ $mr['risk']->ref() }} {{ $mr['risk']->title }}</a><div class="pg-sub">{{ $mr['project']->name }}, {{ $mr['risk']->owner ? 'owned by '.$mr['risk']->owner->name : 'no owner' }}{{ $mr['risk']->escalated_at ? ', escalated' : '' }}</div></div>
        <span class="pg-pill {{ $mr['band'] === 'critical' ? 'pg-pill--solid' : 'pg-pill--negative' }}">{{ ucfirst($mr['band']) }} {{ $mr['score'] }}</span>
    </div>
@empty
    <p class="pg-empty">No live risk is rated high or critical on its project's matrix.</p>
@endforelse
