@php
    $methods = App\Support\Methodologies::ALL;
    $adds = function (array $s) {
        $c = App\Services\ProjectTemplates::counts($s);
        $parts = array_filter([
            $c['phases'] ? $c['phases'].' '.Str::plural('phase', $c['phases']) : null,
            $c['tasks'].' '.Str::plural('task', $c['tasks']),
            $c['gates'] ? $c['gates'].' '.Str::plural('gate', $c['gates']) : null,
            $c['risks'] ? $c['risks'].' '.Str::plural('risk', $c['risks']) : null,
        ]);

        return ucfirst(implode(', ', $parts)).($c['days'] ? ($c['days'] >= 14 ? ', about '.round($c['days'] / 7).' weeks' : ', '.$c['days'].' days') : '').'.';
    };
    $templates = app(App\Services\ProjectTemplates::class);
@endphp
<div>
    <p class="pg-crumb"><a href="{{ route('dashboard') }}">Projects</a></p>
    <div class="pg-titlerow">
        <div>
            <h1 class="pg-title">Templates</h1>
            <p class="pg-finding">@if ($own->isEmpty()){{ auth()->user()->currentTeam->name }} has no templates of its own yet; {{ collect($starters)->flatten(1)->count() }} starters are ready to begin from.@else{{ auth()->user()->currentTeam->name }} has {{ $own->count() }} {{ Str::plural('template', $own->count()) }} of its own, {{ $own->sum('uses') ? 'used '.$own->sum('uses').' '.Str::plural('time', $own->sum('uses')) : 'not used yet' }}.@endif A template brings phases, tasks, gates, risks and budget lines; never people, progress or amounts.</p>
        </div>
        @if ($canCreate)<div class="pg-actions"><a href="{{ route('projects.create') }}" class="pg-btn pg-btn--primary">New project</a></div>@endif
    </div>

    <section class="pg-card" aria-labelledby="own-h">
        <div class="pg-card-head"><h2 id="own-h">{{ auth()->user()->currentTeam->name }}'s templates</h2><small>Saved from projects, in each project's settings</small></div>
        @forelse ($own as $t)
            <div class="pg-tpl" wire:key="t-{{ $t->id }}">
                @if ($editing === $t->id)
                    <form wire:submit="save" class="pg-form pg-tpl-edit">
                        <div class="pg-field"><label for="tn-{{ $t->id }}" class="pg-label">Name</label><input id="tn-{{ $t->id }}" type="text" wire:model="name" maxlength="120" class="pg-input" required></div>
                        <div class="pg-field"><label for="td-{{ $t->id }}" class="pg-label">What it is for</label><input id="td-{{ $t->id }}" type="text" wire:model="description" maxlength="500" class="pg-input"></div>
                        @error('name')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
                        <div class="pg-actions"><button type="submit" class="pg-btn pg-btn--primary pg-btn--sm">Save</button><button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('editing', null)">Cancel</button></div>
                    </form>
                @else
                    <div class="pg-tpl-what">
                        <b>{{ $t->name }}</b>
                        @if ($t->description)<span class="pg-tpl-desc">{{ $t->description }}</span>@endif
                        <span>{{ $t->methodology && isset($methods[$t->methodology]) ? $methods[$t->methodology][0].'. ' : '' }}{{ $adds($t->structure) }} Saved by {{ $t->creator?->name ?? 'someone who has left' }} on {{ $t->created_at->format('j M Y') }}; {{ $t->uses ? 'used '.$t->uses.' '.Str::plural('time', $t->uses) : 'not used yet' }}.</span>
                    </div>
                    <div class="pg-actions">
                        @if ($canCreate)<a href="{{ route('projects.create', ['template' => 'team:'.$t->id]) }}" class="pg-btn pg-btn--secondary pg-btn--sm">Use</a>@endif
                        @if ($templates->canManage(auth()->user(), $t))
                            <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="edit({{ $t->id }})">Rename</button>
                            <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="remove({{ $t->id }})" wire:confirm="Remove {{ $t->name }}? Projects made from it keep everything.">Remove</button>
                        @endif
                    </div>
                    <details class="pg-tpl-more" wire:ignore.self>
                        <summary><span class="material-symbols-rounded" aria-hidden="true">expand_more</span>What it adds</summary>
                        @include('partials.programme.template-outline', ['s' => $t->structure])
                    </details>
                @endif
            </div>
        @empty
            <p class="pg-empty pg-tpl-none">None yet. When a project is shaped the way {{ auth()->user()->currentTeam->name }} works, open its settings and save it as a template: its phases, tasks, checklists, dependencies, gates, risks and budget lines come along, its people, progress and amounts do not.</p>
        @endforelse
    </section>

    <section class="pg-card" aria-labelledby="starter-h" style="margin-top:var(--pg-gap)">
        <div class="pg-card-head"><h2 id="starter-h">Starters</h2><small>First structures for common kinds of project, to edit</small></div>
        @foreach ($starters as $industry => $group)
            <h3 class="pg-tpl-group">{{ App\Support\StarterTemplates::INDUSTRIES[$industry] }}</h3>
            @foreach ($group as $key => $t)
                <div class="pg-tpl" wire:key="s-{{ $key }}">
                    <div class="pg-tpl-what">
                        <b>{{ $t['name'] }}</b>
                        <span class="pg-tpl-desc">{{ $t['description'] }}</span>
                        <span>{{ $methods[$t['methodology']][0] }}. {{ $adds($t['structure']) }}</span>
                    </div>
                    <div class="pg-actions">@if ($canCreate)<a href="{{ route('projects.create', ['template' => 'starter:'.$key]) }}" class="pg-btn pg-btn--secondary pg-btn--sm">Use</a>@endif</div>
                    <details class="pg-tpl-more" wire:ignore.self>
                        <summary><span class="material-symbols-rounded" aria-hidden="true">expand_more</span>What it adds</summary>
                        @include('partials.programme.template-outline', ['s' => $t['structure']])
                    </details>
                </div>
            @endforeach
        @endforeach
    </section>
</div>
