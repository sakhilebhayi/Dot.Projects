<div>
    <div class="pg-titlerow"><div>
        <h1 class="pg-title">Data and security</h1>
        <p class="pg-finding">How long {{ auth()->user()->currentTeam->name }} keeps what builds up as people work, and what protects its data on this server. Only the business owner and admins see this page.</p>
    </div></div>

    <section class="pg-card pg-ret" aria-labelledby="ret-h">
        <div class="pg-card-head"><h2 id="ret-h">How long data is kept</h2></div>
        <form wire:submit="save" class="pg-ret-form">
            <div class="pg-ret-row">
                <div><label for="ret-act" class="pg-label">Activity history</label><p class="pg-help">Who changed what and when: the audit trail. Kept for ever unless you limit it.</p></div>
                <select id="ret-act" wire:model.live="activity" class="pg-select">@foreach (App\Services\DataRetention::ACTIVITY_MONTHS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
            </div>
            <div class="pg-ret-row">
                <div><label for="ret-not" class="pg-label">Read notices</label><p class="pg-help">Notices people have already read. Unread ones are never removed.</p></div>
                <select id="ret-not" wire:model.live="notices" class="pg-select">@foreach (App\Services\DataRetention::NOTICE_DAYS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
            </div>
            <div class="pg-ret-row">
                <div><label for="ret-aut" class="pg-label">Automation run history</label><p class="pg-help">Finished runs of the business's rules. Runs still waiting or awaiting approval are kept.</p></div>
                <select id="ret-aut" wire:model.live="automation" class="pg-select">@foreach (App\Services\DataRetention::AUTOMATION_DAYS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
            </div>
            @error('retention')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
            <div class="pg-actions" style="justify-content:space-between;align-items:center">
                <span class="pg-help">@if ($saved)Saved. It applies tonight at 03:30.@elseif ($policy->last_run_at)Last applied {{ $policy->last_run_at->format('j M Y, H:i') }}: removed {{ $policy->last_removed['activity'] ?? 0 }} activity lines, {{ $policy->last_removed['notices'] ?? 0 }} read notices and {{ $policy->last_removed['automation_runs'] ?? 0 }} automation runs.@else{{ $policy->exists ? 'Applies every night at 03:30.' : 'These are the defaults. Saving makes them this business\'s choice; it applies every night at 03:30.' }}@endif</span>
                <button type="submit" class="pg-btn pg-btn--primary">Save</button>
            </div>
        </form>
        <p class="pg-ret-never">Never removed by these settings: projects, tasks, time, budgets and costs, risks, issues, changes, decisions, gates, meetings, status reports, documents and saved reports. They are the business's records and go only when someone deletes them. Calls sent to other Dot apps are kept 30 days after sending, for every business alike.</p>
    </section>

    <section class="pg-card pg-int" aria-labelledby="sec-h" style="margin-top:var(--pg-gap)">
        <div class="pg-card-head"><h2 id="sec-h">What protects the data</h2><small>as this server is set up now</small></div>
        <dl class="pg-kv pg-sec">
            <dt>Separate businesses</dt><dd>Every page and record checks the business it belongs to, and private projects check membership. An automated test requests every page that takes a record as someone from another business, and must be refused, before any change ships.</dd>
            <dt>Who sees what</dt><dd>Money only for those who run a project and the business's owner and admins; time by person only on projects people run; documents through a check on the project first.</dd>
            <dt>Browser protection</dt><dd>{{ $cspEnforced ? 'A content security policy is enforced: only this app\'s own scripts run.' : 'The content security policy is only reporting at the moment, not enforcing.' }} Pages cannot be framed by other sites.</dd>
            <dt>Connections</dt><dd>HTTPS only{{ $hstsDays ? ', with browsers told to insist on it for '.$hstsDays.' days' : '' }}. Passwords are stored as one-way hashes. Messages queued for other Dot apps are encrypted at rest.</dd>
            <dt>Rate limits</dt><dd>Per person: 300 pages a minute, 600 interactions, 20 report files and 60 document downloads. Sign-in allows 5 attempts a minute.</dd>
            <dt>Backups</dt><dd>A copy of the database is scheduled every night at 02:45, before retention runs. Restoring from it is the next thing to be tested.</dd>
            <dt>Monitoring</dt><dd>Uptime monitors can watch /ready, which checks the database, cache, storage, the scheduler and the queues to other Dot apps, without revealing any business's data.</dd>
        </dl>
    </section>
</div>
