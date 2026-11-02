<div>
    @php
        $c = $o['counts'];
        $n = count($o['rows']);
        $health = ['on_track' => ['On track', 'pg-pill--positive'], 'at_risk' => ['At risk', 'pg-pill--warning'], 'off_track' => ['Off track', 'pg-pill--negative']];
        $r = fn (int $x) => App\Support\Money::rand($x);
    @endphp

    <div class="pg-titlerow">
        <div>
            <h1 class="pg-title">{{ $current ? $current->name : 'All active projects' }}@if ($current)<span class="pg-pill">{{ App\Models\Portfolio::KINDS[$current->kind] ?? 'Portfolio' }}</span>@endif</h1>
            <p class="pg-finding">
                @if ($n === 0)
                    {{ $current ? 'No active project you can see is in this '.mb_strtolower(App\Models\Portfolio::KINDS[$current->kind] ?? 'portfolio').' yet.' : 'No active projects yet.' }}
                @else
                    <b>{{ $n }} active {{ Str::plural('project', $n) }}</b>: {{ $c['on_track'] }} on track, {{ $c['at_risk'] }} at risk, {{ $c['off_track'] }} off track{{ $c['delayed'] ? '; '.$c['delayed'].' delayed' : '' }}.
                    @if (count($o['intervene'])){{ count($o['intervene']) }} {{ count($o['intervene']) === 1 ? 'needs' : 'need' }} intervention.@endif
                @endif
            </p>
            @if ($current && $current->objective)<p class="pg-objective"><b>Objective.</b> {{ $current->objective }}@if ($current->owner) <span class="pg-muted">Owner: {{ $current->owner->name }}.</span>@endif</p>@endif
        </div>
        <div class="pg-actions" style="align-items:center">
            <label for="pf-pick" class="pg-sr">Show</label>
            <select id="pf-pick" wire:model.live="portfolio" class="pg-select" style="width:auto">
                <option value="">All active projects</option>
                @foreach ($portfolios as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
            </select>
            @if ($canManage)<button type="button" class="pg-btn pg-btn--secondary" wire:click="startEdit">Edit</button>@endif
            @if ($oversees)<button type="button" class="pg-btn pg-btn--primary" wire:click="startCreate">New portfolio</button>@endif
        </div>
    </div>

    @if ($editing)
        <section class="pg-card" style="margin-bottom:var(--pg-gap)" aria-labelledby="pf-ed">
            <div class="pg-card-head"><h2 id="pf-ed">{{ $editId ? 'Edit '.$name : 'New portfolio' }}</h2></div>
            <form wire:submit="save" class="pg-form">
                <div class="pg-form-row pg-form-row--3">
                    <div class="pg-field"><label for="pf-name" class="pg-label">Name</label><input id="pf-name" type="text" wire:model="name" maxlength="160" class="pg-input" placeholder="Kathu expansion programme" required>@error('name')<p class="pg-error">{{ $message }}</p>@enderror</div>
                    <div class="pg-field"><label for="pf-kind" class="pg-label">Kind</label><select id="pf-kind" wire:model="kind" class="pg-select">@foreach (App\Models\Portfolio::KINDS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="pg-field"><label for="pf-owner" class="pg-label">Owner</label><select id="pf-owner" wire:model="owner" class="pg-select"><option value="">Nobody yet</option>@foreach ($people as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>@error('owner_id')<p class="pg-error">{{ $message }}</p>@enderror</div>
                </div>
                <div class="pg-field"><label for="pf-obj" class="pg-label">Strategic objective</label><textarea id="pf-obj" wire:model="objective" rows="2" maxlength="2000" class="pg-textarea" placeholder="What these projects together are meant to achieve, and by when."></textarea>@error('objective')<p class="pg-error">{{ $message }}</p>@enderror</div>
                <fieldset class="pg-fieldset">
                    <legend>Projects in it</legend>
                    <div class="pg-people-picks">@foreach ($choosable as $pr)<label class="pg-radio"><input type="checkbox" wire:model="chosen" value="{{ $pr->id }}"> {{ $pr->name }}</label>@endforeach</div>
                    @error('projects')<p class="pg-error">{{ $message }}</p>@enderror
                </fieldset>
                @error('portfolio')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
                <div class="pg-actions" style="justify-content:space-between">
                    <span>@if ($editId && $oversees)<button type="button" class="pg-btn pg-btn--danger pg-btn--sm" wire:click="remove" wire:confirm="Remove this portfolio? Its projects stay as they are.">Remove portfolio</button>@endif</span>
                    <span class="pg-actions"><button type="button" class="pg-btn pg-btn--ghost" wire:click="$set('editing', false)">Cancel</button><button type="submit" class="pg-btn pg-btn--primary">Save</button></span>
                </div>
            </form>
        </section>
    @endif

    @if ($n > 0)
        <div class="pg-healthbar" role="img" aria-label="{{ $c['on_track'] }} on track, {{ $c['at_risk'] }} at risk, {{ $c['off_track'] }} off track">
            @foreach (['off_track', 'at_risk', 'on_track'] as $h)@if ($c[$h])<i class="h-{{ $h }}" style="flex:{{ $c[$h] }}"></i>@endif @endforeach
        </div>
    @endif

    <div class="pg-grid">
        <section class="pg-table-wrap pg-s12" aria-labelledby="pf-h">
            <h2 id="pf-h" style="font-size:14px;font-weight:600;margin:0;padding:12px 16px 4px">Projects</h2>
            @if ($n === 0)
                <p class="pg-empty" style="padding:8px 16px 16px">Projects appear here with their health, delivery and risks.</p>
            @else
                <table class="pg-table pg-table--portfolio">
                    <thead><tr><th scope="col">Project</th><th scope="col">Health</th><th scope="col" class="r">Done</th><th scope="col">Finish</th><th scope="col">Budget</th><th scope="col" class="r">Serious risks</th></tr></thead>
                    <tbody>
                        @foreach ($o['rows'] as $row)
                            @php $p = $row['project']; @endphp
                            <tr wire:key="pf-{{ $p->id }}">
                                <td class="pg-name"><a href="{{ route('projects.show', $p) }}">{{ $p->name }}</a>@if ($row['milestone'])<span class="pg-sub {{ $row['milestone']['late'] ? 'pg-neg' : '' }}">Next: {{ $row['milestone']['title'] }}{{ $row['milestone']['due'] ? ', '.Illuminate\Support\Carbon::parse($row['milestone']['due'])->format('j M') : '' }}</span>@endif</td>
                                <td data-label="Health"><span class="pg-pill {{ $health[$row['health']][1] }}">{{ $health[$row['health']][0] }}</span><span class="pg-sub">{{ Str::limit($row['reasons'][0] ?? '', 60) }}</span>@if ($row['reported'] && $row['reported']['rating'] !== $row['health'])<span class="pg-sub">Last report: {{ mb_strtolower($health[$row['reported']['rating']][0] ?? '') }}</span>@endif</td>
                                <td data-label="Done" class="r">{{ $row['done'] === null ? '–' : $row['done'].'%' }}</td>
                                <td data-label="Finish" class="{{ $row['late'] > 0 ? 'pg-neg' : '' }}">@if ($row['forecast']){{ Illuminate\Support\Carbon::parse($row['forecast'])->format('j M Y') }}@if ($row['late'] > 0)<span class="pg-sub pg-neg">{{ $row['late'] }} {{ Str::plural('day', $row['late']) }} late</span>@endif @else<span class="pg-muted">No dates</span>@endif</td>
                                <td data-label="Budget">@if ($row['money']){{ $r($row['money']['forecast']) }}<span class="pg-sub {{ $row['money']['variance'] < 0 ? 'pg-neg' : '' }}">{{ $row['money']['variance'] < 0 ? $r(-$row['money']['variance']).' over' : 'within '.$r($row['money']['budget']) }}{{ $row['money']['cpi'] !== null ? ', CPI '.number_format($row['money']['cpi'], 2) : '' }}</span>@else<span class="pg-muted">{{ $p->moneyVisibleTo(auth()->user()) ? 'No budget' : 'Not shown' }}</span>@endif</td>
                                <td data-label="Serious risks" class="r {{ $row['risks'] ? '' : 'pg-muted' }}">{{ $row['risks'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

            <section class="pg-card pg-s4" aria-labelledby="iv-h">
                <div class="pg-card-head"><h2 id="iv-h">Needs intervention</h2><small>{{ count($o['intervene']) }}</small></div>
                @forelse ($o['intervene'] as $iv)
                    <div class="pg-row"><div style="min-width:0"><a href="{{ route('projects.show', $iv['project']) }}">{{ $iv['project']->name }}</a>@foreach ($iv['reasons'] as $why)<div class="pg-sub">{{ $why }}</div>@endforeach</div></div>
                @empty
                    <p class="pg-empty">Nothing is off track, escalated or critically blocked.</p>
                @endforelse
            </section>

            <section class="pg-card pg-s4" aria-labelledby="um-h">
                <div class="pg-card-head"><h2 id="um-h">Milestones overdue or due within 30 days</h2></div>
                @forelse ($o['milestones'] as $m)
                    <div class="pg-row"><div style="min-width:0">{{ $m['title'] }}<div class="pg-sub">{{ $m['project']->name }}</div></div><span class="{{ $m['late'] ? 'pg-neg' : '' }}">{{ $m['due']->format('j M') }}</span></div>
                @empty
                    <p class="pg-empty">No milestone is overdue or due in the next 30 days.</p>
                @endforelse
            </section>

            <section class="pg-card pg-s4" aria-labelledby="pm-h">
                <div class="pg-card-head"><h2 id="pm-h">Money and people</h2></div>
                <dl class="pg-kv">
                    @if ($o['money']['projects'])
                        <dt>Budget</dt><dd>{{ $r($o['money']['budget']) }} across {{ $o['money']['projects'] }} {{ Str::plural('project', $o['money']['projects']) }}</dd>
                        <dt>Forecast</dt><dd class="{{ $o['money']['forecast'] > $o['money']['budget'] ? 'pg-neg' : '' }}">{{ $r($o['money']['forecast']) }}</dd>
                    @endif
                    @if ($o['money']['hidden'])<dt>Not shown</dt><dd>{{ $o['money']['hidden'] }} {{ Str::plural('project', $o['money']['hidden']) }} whose money you do not see</dd>@endif
                    @if ($overCapacity !== null)<dt>People</dt><dd><a href="{{ route('workload.index') }}">{{ $overCapacity }} over capacity</a> in the next six weeks</dd>@endif
                    @unless ($current)<dt>Alignment</dt><dd>{{ $unaligned }} active {{ Str::plural('project', $unaligned) }} in no portfolio or programme</dd>@endunless
                </dl>
            </section>
    </div>
    <p class="pg-help" style="margin-top:12px">Health is worked out from each project's own records today, the same way a status report suggests its rating; a project's last published report can say otherwise, and is shown when it does. Money shows only for projects whose money you may see.</p>
</div>
