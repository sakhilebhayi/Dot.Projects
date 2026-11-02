{{-- The six-month milestone roadmap. Needs $rm (PortfolioOverview::roadmap) and $k, a wire:key prefix. --}}
@php
    $state = ['due' => 'Due', 'late' => 'Late', 'done' => 'Complete'];
@endphp
@if ($rm['lanes'] === [])
    <p class="pg-empty">No milestone falls in the next six months{{ $rm['undated'] ? ', and '.$rm['undated'].' open '.Str::plural('milestone', $rm['undated']).' '.($rm['undated'] === 1 ? 'has' : 'have').' no date' : '' }}.</p>
@else
    <div class="pg-road-scroll">
        <div class="pg-road" role="list" aria-label="Milestones by project, {{ $rm['from']->format('F Y') }} to {{ $rm['to']->format('F Y') }}">
            <div class="pg-road-head" aria-hidden="true">
                <span></span>
                <div class="pg-road-track">@foreach ($rm['months'] as $mo)<span class="pg-road-month" style="left:{{ $mo['at'] }}%">{{ $mo['label']->format('M') }}</span>@endforeach</div>
            </div>
            @foreach ($rm['lanes'] as $lane)
                <div class="pg-road-lane" role="listitem" wire:key="{{ $k }}-{{ $lane['project']->id }}">
                    <a class="pg-road-name" href="{{ route('projects.schedule', $lane['project']) }}">{{ $lane['project']->name }}</a>
                    <div class="pg-road-track">
                        @foreach ($rm['months'] as $mo)<i class="pg-road-rule" style="left:{{ $mo['at'] }}%" aria-hidden="true"></i>@endforeach
                        <i class="pg-road-today" style="left:{{ $rm['today'] }}%" aria-hidden="true"></i>
                        <ul class="pg-road-marks">
                            @foreach ($lane['marks'] as $mk)
                                @php
                                    $tip = collect($mk['items'])->map(fn ($i) => Str::limit($i['title'], 32).', '.$i['due']->format('j M').($i['state'] === 'due' ? '' : ', '.mb_strtolower($state[$i['state']])));
                                @endphp
                                <li class="pg-road-mark is-{{ $mk['state'] }}{{ $mk['at'] < 15 ? ' at-start' : ($mk['at'] > 85 ? ' at-end' : '') }}" style="left:{{ $mk['at'] }}%" tabindex="0" data-tip="{{ count($mk['items']) > 1 ? count($mk['items']).' milestones: '.$tip->join('; ') : $tip->first() }}">@if (count($mk['items']) > 1)<b class="pg-road-count" aria-hidden="true">{{ count($mk['items']) }}</b>@endif<span class="pg-sr">@foreach ($mk['items'] as $i){{ $i['title'] }}, {{ $i['due']->format('j M Y') }}, {{ mb_strtolower($state[$i['state']]) }}. @endforeach</span></li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    <p class="pg-road-key"><span><i class="pg-road-mark is-due" aria-hidden="true"></i> Due</span><span><i class="pg-road-mark is-late" aria-hidden="true"></i> Late, not complete</span><span><i class="pg-road-mark is-done" aria-hidden="true"></i> Complete</span><span><i class="pg-road-today pg-road-today--key" aria-hidden="true"></i> Today</span>@if ($rm['undated'])<span class="pg-muted">{{ $rm['undated'] }} open {{ Str::plural('milestone', $rm['undated']) }} without a date</span>@endif</p>
@endif
