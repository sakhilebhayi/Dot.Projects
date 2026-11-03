<div>
    @php
        $pill = fn (string $kind, string $text) => ['live' => 'pg-pill--positive', 'warn' => 'pg-pill--warning', 'off' => ''][$kind].'|'.$text;
        $notifyState = ! $notifyOn ? $pill('off', 'Not set up on this server') : (! $linked ? $pill('warn', 'Business not linked to InfoDot') : ($counts['given_up'] ? $pill('warn', 'Some notices not sent') : $pill('live', 'Set up')));
        $analyticsState = ! $analyticsOn ? $pill('off', 'Not set up on this server') : (! $linked ? $pill('warn', 'Business not linked to InfoDot')
            : ($analyticsLast?->status === 'given_up' ? $pill('warn', 'Last snapshot not sent') : $pill('live', 'Set up')));
        $labels = ['active' => 'Active projects', 'off_track' => 'Off track', 'at_risk' => 'At risk', 'over_budget' => 'Forecast over budget',
            'overdue_tasks' => 'Overdue tasks', 'tasks_completed_7d' => 'Tasks completed, 7 days', 'late_milestones' => 'Milestones past due', 'serious_risks' => 'Serious risks'];
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

        <div class="pg-int-row">
            <div class="pg-int-what"><b>Dot.Files</b><span>Project and task documents, filed in the business's Dot.Files workspace.</span></div>
            @php [$c, $t] = explode('|', ! $filesOn ? $pill('off', 'Not set up on this server') : (! $linked ? $pill('warn', 'Business not linked to InfoDot') : (($filesLast && ! $filesLast['ok']) ? $pill('warn', 'Last call failed') : $pill('live', 'Set up')))); @endphp
            <span class="pg-pill {{ $c }}">{{ $t }}</span>
            <div class="pg-int-detail">
                @if (! $filesOn)
                    <p>Documents are switched off. To switch them on, the server needs FILES_PLATFORM_SECRET, and Dot.Files needs the same secret as DOT_SERVICE_SECRET_PROJECTS.</p>
                @else
                    <p>Documents are filed one folder per project, “Dot.Projects - &lt;project&gt;”. People open them through Dot.Projects, which checks the project first.</p>
                    <dl class="pg-kv">
                        <dt>Documents</dt><dd>{{ $documents }} filed by this business's projects</dd>
                        <dt>Last call</dt><dd>{{ $filesLast ? Illuminate\Support\Carbon::parse($filesLast['at'])->format('j M Y, H:i').' UTC, '.($filesLast['ok'] ? 'it worked' : $filesLast['message']) : 'None yet' }}</dd>
                    </dl>
                @endif
            </div>
        </div>

        <div class="pg-int-row">
            <div class="pg-int-what"><b>Dot.Analytics</b><span>The business's delivery numbers in its analytics, every morning.</span></div>
            @php [$c, $t] = explode('|', $analyticsState); @endphp
            <span class="pg-pill {{ $c }}">{{ $t }}</span>
            <div class="pg-int-detail">
                @if (! $analyticsOn)
                    <p>Nothing is sent. To switch it on, the server needs ANALYTICS_INGEST_SECRET, and Dot.Analytics needs the same secret as ANALYTICS_INGEST_SECRET_PROJECTS.</p>
                @elseif (! $linked)
                    <p>Nothing is sent until this business is linked to its InfoDot company, which is how Dot.Analytics recognises it.</p>
                @else
                    <p>Once a day at 04:30 UTC, numbers only: no project names, people or text leave Dot.Projects. Today they would be:</p>
                    <dl class="pg-kv">
                        @foreach ($analyticsNow as $key => $n)<dt>{{ $labels[$key] ?? $key }}</dt><dd>{{ $n }}</dd>@endforeach
                        <dt>Last sent</dt><dd>{{ $analyticsSentAt ? Illuminate\Support\Carbon::parse($analyticsSentAt)->format('j M Y, H:i').' UTC' : 'Nothing yet' }}</dd>
                    </dl>
                    @if ($analyticsLast?->status === 'given_up')
                        <p class="pg-int-problems">The last snapshot was not sent. {{ $analyticsLast->last_error }} @if ($analyticsLast->last_status)(answered {{ $analyticsLast->last_status }})@endif <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="sendAgain({{ $analyticsLast->id }})">Send again</button></p>
                    @endif
                @endif
            </div>
        </div>
    </section>

    <section class="pg-card pg-int" aria-labelledby="int-next" style="margin-top:var(--pg-gap)">
        <div class="pg-card-head"><h2 id="int-next">Not connected yet</h2></div>
        @foreach ([
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
