@php
    $methods = App\Support\Methodologies::ALL;
    $summary = function (array $s, ?string $methodology) use ($methods) {
        $c = App\Services\ProjectTemplates::counts($s);
        $parts = array_filter([
            $c['phases'] ? $c['phases'].' '.Str::plural('phase', $c['phases']) : null,
            $c['tasks'].' '.Str::plural('task', $c['tasks']),
            $c['gates'] ? $c['gates'].' '.Str::plural('gate', $c['gates']) : null,
        ]);
        $length = $c['days'] ? ($c['days'] >= 14 ? ', about '.round($c['days'] / 7).' weeks' : ', '.$c['days'].' days') : '';

        return ($methodology && isset($methods[$methodology]) ? $methods[$methodology][0].'. ' : '').ucfirst(implode(', ', $parts)).$length.'.';
    };
@endphp
<div class="pg-grid pg-new">
    <section class="pg-card pg-s5 pg-new-from" aria-labelledby="from-h">
        <div class="pg-card-head"><h2 id="from-h">How to start</h2><a href="{{ route('templates.index') }}">Templates</a></div>
        <div role="radiogroup" aria-labelledby="from-h">
            <label class="pg-src">
                <input type="radio" wire:model.live="source" value="" class="pg-src-in">
                <span class="pg-src-what"><b>Blank project</b><span>A name is enough. Add milestones, tasks and people as you go.</span></span>
            </label>

            <p class="pg-src-group">{{ auth()->user()->currentTeam?->name ?? 'Your business' }}'s templates</p>
            @forelse ($own as $t)
                <label class="pg-src" wire:key="own-{{ $t->id }}">
                    <input type="radio" wire:model.live="source" value="team:{{ $t->id }}" class="pg-src-in">
                    <span class="pg-src-what"><b>{{ $t->name }}</b><span>{{ $summary($t->structure, $t->methodology) }}</span></span>
                </label>
            @empty
                <p class="pg-src-none">None yet. Shape a project the way your business works, then save it as a template from its settings.</p>
            @endforelse

            @foreach ($starters as $industry => $group)
                <p class="pg-src-group">{{ App\Support\StarterTemplates::INDUSTRIES[$industry] }}</p>
                @foreach ($group as $key => $t)
                    <label class="pg-src" wire:key="starter-{{ $key }}">
                        <input type="radio" wire:model.live="source" value="starter:{{ $key }}" class="pg-src-in">
                        <span class="pg-src-what"><b>{{ $t['name'] }}</b><span>{{ $summary($t['structure'], $t['methodology']) }}</span></span>
                    </label>
                @endforeach
            @endforeach
        </div>
    </section>

    <div class="pg-s7 pg-new-side">
        @if ($chosen)
            <section class="pg-card" aria-labelledby="adds-h" wire:key="adds-{{ $chosen['ref'] }}">
                <div class="pg-card-head"><h2 id="adds-h">What {{ $chosen['name'] }} adds</h2><small>{{ $chosen['model'] ? 'Saved by '.($chosen['model']->creator?->name ?? 'someone in the business') : 'A starter to edit' }}</small></div>
                @if ($chosen['description'])<p class="pg-tdesc">{{ $chosen['description'] }}</p>@endif
                @include('partials.programme.template-outline', ['s' => $chosen['structure']])
                <p class="pg-help pg-tnote">Dates are laid out from the start you choose. Every task starts not started and unassigned; nothing is fixed once the project exists.</p>
            </section>
        @endif

        <section class="pg-card" aria-labelledby="details-h">
            <div class="pg-card-head"><h2 id="details-h">The project</h2></div>
            <form wire:submit="save" class="pg-form">
                <div class="pg-field">
                    <label for="livewire-projects-create-project-name" class="pg-label">Name</label>
                    <input id="livewire-projects-create-project-name" wire:model="name" type="text" maxlength="255" placeholder="Fleet system rollout" class="pg-input" required>
                    @error('name')<p class="pg-error">{{ $message }}</p>@enderror
                </div>

                <div class="pg-field">
                    <label for="livewire-projects-create-project-description" class="pg-label">What it should achieve</label>
                    <textarea id="livewire-projects-create-project-description" wire:model="description" rows="3" class="pg-textarea" placeholder="Put the new fleet system live at the three mine sites by February."></textarea>
                    <p class="pg-help">{{ $chosen === null && $this->aiReady() ? 'The more you say here, the better the drafted plan.' : 'Optional. You can add scope and people as you go.' }}</p>
                    @error('description')<p class="pg-error">{{ $message }}</p>@enderror
                </div>

                <div class="pg-form-row {{ $chosen === null ? 'pg-form-row--3' : '' }}">
                    @if ($chosen === null)
                        <div class="pg-field">
                            <label for="livewire-projects-create-project-status" class="pg-label">Status</label>
                            <select id="livewire-projects-create-project-status" wire:model="status" class="pg-select">
                                <option value="planning">Planning</option>
                                <option value="active">Active</option>
                                <option value="on_hold">On hold</option>
                                <option value="completed">Completed</option>
                            </select>
                            @error('status')<p class="pg-error">{{ $message }}</p>@enderror
                        </div>
                    @endif
                    <div class="pg-field">
                        <label for="livewire-projects-create-project-startdate" class="pg-label">Starts</label>
                        <input id="livewire-projects-create-project-startdate" wire:model="startDate" type="date" class="pg-input" @required($chosen !== null)>
                        @error('startDate')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="livewire-projects-create-project-duedate" class="pg-label">Due</label>
                        <input id="livewire-projects-create-project-duedate" wire:model="dueDate" type="date" class="pg-input">
                        @if ($chosen && ($days = App\Services\ProjectTemplates::counts($chosen['structure'])['days']))<p class="pg-help">Leave it empty to take the template's {{ $days >= 14 ? 'about '.round($days / 7).' weeks' : $days.' days' }}.</p>@endif
                        @error('dueDate')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="pg-field">
                    <label for="livewire-projects-create-project-methodology" class="pg-label">How it is run</label>
                    <select id="livewire-projects-create-project-methodology" wire:model.live="methodology" class="pg-select">
                        <option value="">Not said</option>
                        @foreach ($methods as $key => [$label])<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                    <p class="pg-help">{{ $methodology !== '' && isset($methods[$methodology]) ? $methods[$methodology][1] : 'Optional. Every view works whichever you choose; this says how the project is run and where to follow it.' }}</p>
                    @error('methodology')<p class="pg-error">{{ $message }}</p>@enderror
                </div>

                <fieldset class="pg-fieldset">
                    <legend>Who can see it</legend>
                    <div style="display:grid;gap:8px">
                        <label class="pg-radio"><input type="radio" wire:model="visibility" value="team"> Everyone in {{ auth()->user()->currentTeam?->name ?? 'the business' }}</label>
                        <label class="pg-radio"><input type="radio" wire:model="visibility" value="private"> Only people I add (private)</label>
                    </div>
                    <p class="pg-help" style="margin:6px 0 0">You can add people and change this later in the project's settings.</p>
                </fieldset>

                @if ($aiError)
                    <div class="pg-notice" role="alert" style="margin:0">{{ $aiError }}</div>
                @endif

                @if ($chosen === null && ! $this->aiReady())
                    <p class="pg-help" style="margin:0">AI planning is not set up here. The example plan adds three generic milestones with tasks, not tailored to this project, for you to edit or delete.</p>
                @endif

                <div class="pg-actions" style="justify-content:flex-end;border-top:1px solid var(--pg-rule);padding-top:14px">
                    @if ($chosen === null)
                        <button type="button" wire:click="generatePlan" wire:loading.attr="disabled" class="pg-btn pg-btn--secondary">
                            <span wire:loading.remove wire:target="generatePlan">{{ $this->aiReady() ? 'Create and draft a plan with AI' : 'Create with an example plan' }}</span>
                            <span wire:loading wire:target="generatePlan">{{ $this->aiReady() ? 'Drafting the plan…' : 'Adding the example…' }}</span>
                        </button>
                    @endif
                    <button type="submit" class="pg-btn pg-btn--primary" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">{{ $chosen ? 'Create from template' : 'Create project' }}</span>
                        <span wire:loading wire:target="save">Creating…</span>
                    </button>
                </div>
            </form>
        </section>
    </div>
</div>
