<div>
    @php
        $draft = $reports->firstWhere('status', 'draft');
        $last = $reports->firstWhere('status', 'published');
        $pill = ['on_track' => 'pg-pill--positive', 'at_risk' => 'pg-pill--warning', 'off_track' => 'pg-pill--negative'];
    @endphp

    <div class="pg-titlerow" style="margin-top:0">
        <p class="pg-finding" style="margin:0">
            @if ($last)
                Last report: <b>{{ $last->ref() }}</b>, {{ $last->period_from->format('j M') }} to {{ $last->period_to->format('j M') }}, {{ mb_strtolower(App\Models\ProjectReport::RATINGS[$last->overall] ?? '') }}. {{ $last->period_to->lt(today()->subDays(7)) ? 'Its period ended more than a week ago.' : '' }}
            @else
                No status reports yet. A report sets down where the project stands for a period, in the manager's words, with the facts frozen beside them.
            @endif
        </p>
        @if ($canEdit)
            <div class="pg-actions"><button type="button" class="pg-btn pg-btn--primary" wire:click="start">{{ $draft ? 'Continue the draft '.$draft->ref() : 'Write a status report' }}</button></div>
        @endif
    </div>

    <section class="pg-table-wrap" aria-labelledby="rl-h">
        <h2 id="rl-h" style="font-size:14px;font-weight:600;margin:0;padding:12px 16px 4px">Status reports</h2>
        @if ($reports->isEmpty())
            <p class="pg-empty" style="padding:8px 16px 16px">Published reports are listed here, newest first, and can be printed or shared as they were sent.</p>
        @else
            <table class="pg-table pg-table--reports">
                <thead><tr><th scope="col">Report</th><th scope="col">Period</th><th scope="col">Published</th><th scope="col">Rating</th></tr></thead>
                <tbody>
                    @foreach ($reports as $r)
                        <tr wire:key="rep-{{ $r->id }}">
                            <td class="pg-name"><span class="pg-id">{{ $r->ref() }}</span> <a href="{{ route('projects.reports.show', [$project, $r]) }}">Status report</a></td>
                            <td data-label="Period">{{ $r->period_from->format('j M') }} to {{ $r->period_to->format('j M Y') }}</td>
                            <td data-label="Published" class="{{ $r->published() ? '' : 'pg-muted' }}">{{ $r->published() ? $r->published_at->format('j M Y').', '.($r->publisher?->name ?? 'someone') : 'Draft, not sent' }}</td>
                            <td data-label="Status">@if ($r->overall)<span class="pg-pill {{ $pill[$r->overall] ?? '' }}">{{ App\Models\ProjectReport::RATINGS[$r->overall] }}</span>@else<span class="pg-muted">Not rated</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
