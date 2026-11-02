<div>
    @php
        $pill = fn (string $kind, string $text) => ['live' => 'pg-pill--positive', 'warn' => 'pg-pill--warning', 'off' => ''][$kind].'|'.$text;
        $notifyState = ! $notifyOn ? $pill('off', 'Not set up on this server') : (! $linked ? $pill('warn', 'Business not linked to InfoDot') : ($counts['given_up'] ? $pill('warn', 'Some notices not sent') : $pill('live', 'Set up')));
    @endphp
    <div class="pg-titlerow"><div>
        <h1 class="pg-title">Integrations</h1>
        <p class="pg-finding">Dot.Projects works on its own; these connect it to the rest of Dot for {{ $team->name }}. Each says whether it is set up and what it actually did. Only the business owner and admins see this page.</p>
    </div></div>

    <section class="pg-card pg-int" aria-labelledby="int-on">
        <div class="pg-card-head"><h2 id="int-on">Connected</h2></div>

        <div class="pg-int-row">
            <div class="pg-int-what"><b>InfoDot</b><span>Sign-in and who belongs to the business.</span></div>
            @php [$c, $t] = explode('|', $infodot ? $pill('live', 'Live') : $pill('off', 'Not set up on this server')); @endphp
            <span class="pg-pill {{ $c }}">{{ $t }}</span>
            <p class="pg-int-detail">{{ $linked ? 'This business is linked to its InfoDot company.' : 'This business is not linked to an InfoDot company yet, so other Dot platforms cannot recognise it.' }}</p>
        </div>

        <div class="pg-int-row">
            <div class="pg-int-what"><b>Dot.Notify</b><span>Every notice in Dot.Projects can also reach people by email, through Notify.</span></div>
            @php [$c, $t] = explode('|', $notifyState); @endphp
            <span class="pg-pill {{ $c }}">{{ $t }}</span>
            <div class="pg-int-detail">
                @if (! $notifyOn)
                    <p>Nothing is sent. To switch it on, the server needs NOTIFY_INGEST_URL and NOTIFY_INGEST_SECRET, and Dot.Notify needs the same secret as NOTIFY_INGEST_SECRET_PROJECTS.</p>
                @else
                    <p>Only the title of a notice, its project and a link go to Notify; what the notice says stays here. Each kind of notice sends email only once the business switches it on in Notify.</p>
                    <dl class="pg-kv">
                        <dt>Last 7 days</dt><dd>{{ $counts['sent'] }} sent, {{ $counts['pending'] }} waiting, {{ $counts['given_up'] }} not sent</dd>
                        <dt>Last sent</dt><dd>{{ $lastSent ? Illuminate\Support\Carbon::parse($lastSent)->format('j M Y, H:i').' UTC' : 'Nothing yet' }}</dd>
                    </dl>
                @endif
                @if ($problems->isNotEmpty())
                    <div class="pg-int-problems">
                        <div class="pg-card-head"><h3>Not sent, or still trying</h3>@if ($problems->contains('status', 'given_up'))<button type="button" class="pg-btn pg-btn--secondary pg-btn--sm" wire:click="sendAllAgain">Send all again</button>@endif</div>
                        @foreach ($problems as $p)
                            <div class="pg-row" wire:key="d-{{ $p->id }}">
                                <div style="min-width:0">{{ str_replace(['projects.', '_'], ['', ' '], $p->event) }}<div class="pg-sub">{{ $p->created_at->format('j M, H:i') }}, {{ $p->attempts }} {{ Str::plural('attempt', $p->attempts) }}{{ $p->last_status ? ', answered '.$p->last_status : '' }}. {{ $p->last_error }}</div></div>
                                <span class="pg-actions" style="align-items:center">
                                    <span class="pg-pill {{ $p->status === 'given_up' ? 'pg-pill--warning' : '' }}">{{ App\Models\IntegrationDelivery::STATUSES[$p->status] ?? $p->status }}</span>
                                    @if ($p->status === 'given_up')<button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="sendAgain({{ $p->id }})">Send again</button>@endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </section>

    <section class="pg-card pg-int" aria-labelledby="int-next" style="margin-top:var(--pg-gap)">
        <div class="pg-card-head"><h2 id="int-next">Not connected yet</h2></div>
        @foreach ([
            ['Dot.Files', 'Project and task documents, kept in Dot.Files.', 'Next', 'Dot.Files has a platform API for storing and fetching a business\'s files; project documents will use it next.'],
            ['Dot.Analytics', 'Project and portfolio numbers in the business\'s analytics.', 'Waiting on Dot.Analytics', 'Dot.Analytics accepts daily numbers only from platforms in its catalogue, and Dot.Projects is not in it yet.'],
            ['Dot.Memory and Dot.Brain', 'Lessons learned and decisions as organisational knowledge.', 'Later', 'Both have APIs; what Dot.Projects should share with them is still to be agreed.'],
            ['Dot.HR, Dot.Finance, Dot.Tasks', 'Leave-aware capacity, finance workflows, shared tasks.', 'No API yet', 'These apps have no API Dot.Projects can call. Until they do, capacity, costs and tasks are kept here.'],
        ] as [$name, $what, $state, $why])
            <div class="pg-int-row">
                <div class="pg-int-what"><b>{{ $name }}</b><span>{{ $what }}</span></div>
                <span class="pg-pill">{{ $state }}</span>
                <p class="pg-int-detail">{{ $why }}</p>
            </div>
        @endforeach
    </section>
</div>
