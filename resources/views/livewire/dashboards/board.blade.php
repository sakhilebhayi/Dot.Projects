<div>
    <div class="pg-titlerow">
        <div style="min-width:0">
            <h1 class="pg-title">{{ $current ? $current->name : 'Dashboards' }}@if ($current && $current->shared)<span class="pg-pill">Shared</span>@endif</h1>
            <p class="pg-finding">
                @if ($current === null)
                    A dashboard puts the reports and views you check most on one page. Each widget shows what you may see, so a shared dashboard shows each person their own picture.
                @elseif ($current->user_id === auth()->id())
                    Yours{{ $current->shared ? ', shared with the business' : ', only you see it' }}. {{ count($items) }} {{ Str::plural('widget', count($items)) }}.
                @else
                    Shared by {{ $current->user->name }}. Each widget shows what you may see.
                @endif
            </p>
        </div>
        <div class="pg-actions" style="align-items:center">
            @if ($all->isNotEmpty())
                <label for="db-pick" class="pg-sr">Dashboard</label>
                <select id="db-pick" wire:model.live="dashboard" class="pg-select" style="width:auto;max-width:240px">
                    @foreach ($all as $d)<option value="{{ $d->id }}" @selected($current && $d->id === $current->id)>{{ $d->name }}{{ $d->user_id === auth()->id() ? '' : ' (shared)' }}</option>@endforeach
                </select>
            @endif
            @if ($current && $canManage && ! $editing)<button type="button" class="pg-btn pg-btn--secondary" wire:click="startEdit">Arrange</button>@endif
            @if ($current && ! $canManage)<button type="button" class="pg-btn pg-btn--secondary" wire:click="copy">Copy to my dashboards</button>@endif
            @if ($editing)<button type="button" class="pg-btn pg-btn--primary" wire:click="$set('editing', false)">Done</button>@else<button type="button" class="pg-btn pg-btn--primary" wire:click="$set('creating', true)">New dashboard</button>@endif
        </div>
    </div>
    @error('dashboard')<p class="pg-error" role="alert" style="margin:0 0 12px">{{ $message }}</p>@enderror

    @if ($creating)
        <form wire:submit="create" class="pg-card pg-dash-form">
            <div class="pg-field" style="flex:1 1 260px"><label for="db-new" class="pg-label">Name</label><input id="db-new" type="text" wire:model="newName" maxlength="80" class="pg-input" placeholder="Monday review" required autofocus>@error('name')<p class="pg-error">{{ $message }}</p>@enderror</div>
            <span class="pg-actions"><button type="button" class="pg-btn pg-btn--ghost" wire:click="$set('creating', false)">Cancel</button><button type="submit" class="pg-btn pg-btn--primary">Make it</button></span>
        </form>
    @endif

    @if ($editing && $current)
        <form wire:submit="saveSettings" class="pg-card pg-dash-form">
            <div class="pg-field" style="flex:1 1 260px"><label for="db-name" class="pg-label">Name</label><input id="db-name" type="text" wire:model="name" maxlength="80" class="pg-input" required>@error('name')<p class="pg-error">{{ $message }}</p>@enderror</div>
            <label class="pg-radio"><input type="checkbox" wire:model="shared"> Share with {{ auth()->user()->currentTeam->name }}</label>
            <span class="pg-actions">
                <button type="button" class="pg-btn pg-btn--danger pg-btn--sm" wire:click="remove" wire:confirm="Delete this dashboard? The reports and projects it shows are not touched.">Delete dashboard</button>
                <button type="submit" class="pg-btn pg-btn--secondary pg-btn--sm">Save name and sharing</button>
            </span>
            <p class="pg-help" style="flex-basis:100%">Sharing shares the arrangement, not the rows: each person sees only the projects, money and time they may see.</p>
        </form>
    @endif

    @if ($current === null)
        @unless ($creating)
            <section class="pg-card pg-dash-empty" aria-labelledby="db-start">
                <h2 id="db-start">Start with one made for you</h2>
                <p>Your work and your time this week, the health of the projects you can see, their major risks and the roadmap. Arrange or remove any of it afterwards.</p>
                <span class="pg-actions"><button type="button" class="pg-btn pg-btn--primary" wire:click="starter">Make my dashboard</button><button type="button" class="pg-btn pg-btn--ghost" wire:click="$set('creating', true)">Start from nothing</button></span>
            </section>
        @endunless
    @else
        <div class="pg-dash">
            @forelse ($items as $n => $it)
                @php $w = $it['widget']; @endphp
                <section class="pg-card pg-widget{{ $w->wide ? ' is-wide' : '' }}" wire:key="w-{{ $w->id }}" aria-labelledby="w-h-{{ $w->id }}">
                    <div class="pg-card-head">
                        <h2 id="w-h-{{ $w->id }}">{{ $it['heading'] }}</h2>
                        @if ($editing)
                            <span class="pg-widget-tools">
                                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="moveWidget({{ $w->id }}, -1)" @disabled($n === 0) aria-label="Move {{ $it['heading'] }} earlier"><span class="material-symbols-rounded" aria-hidden="true">arrow_upward</span></button>
                                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="moveWidget({{ $w->id }}, 1)" @disabled($loop->last) aria-label="Move {{ $it['heading'] }} later"><span class="material-symbols-rounded" aria-hidden="true">arrow_downward</span></button>
                                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="toggleWide({{ $w->id }})">{{ $w->wide ? 'Half width' : 'Full width' }}</button>
                                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="removeWidget({{ $w->id }})" aria-label="Remove {{ $it['heading'] }}">Remove</button>
                            </span>
                        @elseif (! empty($it['data']['scope']))
                            <small>{{ $it['data']['scope'] }}</small>
                        @endif
                    </div>
                    @include('partials.programme.widget', ['w' => $w, 'd' => $it['data']])
                </section>
            @empty
                <p class="pg-empty pg-dash-none">Nothing on this dashboard yet.{{ $canManage ? ' Add a widget below.' : '' }}</p>
            @endforelse
        </div>

        @if ($editing)
            <form wire:submit="addWidget" class="pg-card pg-dash-add" aria-labelledby="db-add">
                <div class="pg-card-head"><h2 id="db-add">Add a widget</h2><small>{{ count($items) }} of {{ App\Services\Dashboards::MAX_WIDGETS }}</small></div>
                <fieldset class="pg-dash-kinds">
                    <legend class="pg-sr">What it shows</legend>
                    @foreach (App\Services\DashboardWidgets::KINDS as $k => $kind)
                        <label class="pg-dash-kind"><input type="radio" wire:model.live="wKind" value="{{ $k }}"><span><b>{{ $kind['label'] }}</b>{{ $kind['about'] }}</span></label>
                    @endforeach
                </fieldset>
                <div class="pg-form-row pg-form-row--3">
                    @if ($wKind === 'report')
                        <div class="pg-field"><label for="w-src" class="pg-label">Report</label>
                            <select id="w-src" wire:model="wSource" class="pg-select">
                                @if ($savedReports->isNotEmpty())<optgroup label="Saved reports">@foreach ($savedReports as $s)<option value="saved:{{ $s->id }}">{{ $s->name }}</option>@endforeach</optgroup>@endif
                                <optgroup label="Standard reports">@foreach (App\Services\Reporting::REPORTS as $rk => $r)<option value="{{ $rk }}">{{ $r['label'] }}</option>@endforeach</optgroup>
                            </select>
                        </div>
                        <div class="pg-field"><label for="w-rows" class="pg-label">Rows</label><select id="w-rows" wire:model="wRows" class="pg-select">@foreach (App\Services\DashboardWidgets::ROWS as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select></div>
                    @elseif (App\Services\DashboardWidgets::KINDS[$wKind]['portfolio'] ?? false)
                        <div class="pg-field"><label for="w-pf" class="pg-label">Projects</label><select id="w-pf" wire:model="wPortfolio" class="pg-select"><option value="">Every open project you can see</option>@foreach ($portfolios as $pf)<option value="{{ $pf->id }}">{{ $pf->name }}</option>@endforeach</select></div>
                    @endif
                    <div class="pg-field"><label for="w-title" class="pg-label">Title <span class="pg-muted">(optional)</span></label><input id="w-title" type="text" wire:model="wTitle" maxlength="80" class="pg-input" placeholder="{{ App\Services\DashboardWidgets::KINDS[$wKind]['label'] ?? '' }}"></div>
                </div>
                @error('widget')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
                <div class="pg-actions"><button type="submit" class="pg-btn pg-btn--primary">Add widget</button></div>
            </form>
        @endif
    @endif
</div>
