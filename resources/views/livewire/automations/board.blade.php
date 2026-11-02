<div class="pg-rep pg-auto">
    @php
        $C = App\Services\AutomationCatalog::class;
        $statusPill = ['done' => 'pg-pill--positive', 'failed' => 'pg-pill--negative', 'skipped' => ''];
    @endphp

    <aside class="pg-rep-side" aria-label="Rules">
        <h2 class="pg-rep-h">Rules</h2>
        @if ($rules->isEmpty())
            <p class="pg-empty pg-rep-none">No rules yet. Start from a template, or make one.</p>
        @else
            <ul class="pg-rep-list">
                @foreach ($rules as $r)
                    <li wire:key="r-{{ $r->id }}"><button type="button" wire:click="select({{ $r->id }})" @if (! $building && $selected && $selected->id === $r->id) aria-current="true" @endif>{{ $r->name }}<span>{{ $r->active ? 'On' : 'Off' }}, {{ $r->project_id ? $r->getAttribute('project_name') : 'every project' }}</span></button></li>
                @endforeach
            </ul>
        @endif
        @if ($oversees || $scopes->isNotEmpty())
            <button type="button" class="pg-btn pg-btn--primary pg-btn--sm pg-auto-new" wire:click="startNew">New rule</button>
            <ul class="pg-rep-list pg-auto-more"><li><button type="button" wire:click="showTemplates" @if (! $building && ! $selected) aria-current="true" @endif>Templates</button></li></ul>
        @endif
    </aside>

    <section class="pg-rep-main">
        @if ($building)
            <div class="pg-titlerow"><div><h1 class="pg-title">{{ $editId ? 'Change the rule' : 'New rule' }}</h1>
                <p class="pg-finding">{{ $draftSentence ?? 'Finish the rule to read what it will do.' }}</p></div></div>
            @if ($draftError)<p class="pg-auto-todo" role="status">{{ $draftError }}</p>@endif

            <form wire:submit="save" class="pg-card pg-auto-form">
                <div class="pg-form-row">
                    <div class="pg-field"><label for="a-name" class="pg-label">Name</label><input id="a-name" type="text" wire:model.live.debounce.500ms="form.name" maxlength="120" class="pg-input" placeholder="Tell the managers when a milestone is completed"></div>
                    <div class="pg-field"><label for="a-scope" class="pg-label">In</label>
                        <select id="a-scope" wire:model.live="form.project_id" class="pg-select">
                            @if ($oversees)<option value="">Every project in the business</option>@endif
                            @foreach ($scopes as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                        </select>
                    </div>
                </div>

                <ol class="pg-auto-flow">
                    <li class="pg-auto-step">
                        <h2>When</h2>
                        <div class="pg-auto-row">
                            <label for="a-trigger" class="pg-sr">What starts the rule</label>
                            <select id="a-trigger" wire:model.live="form.trigger" class="pg-select">
                                <optgroup label="When something happens">@foreach ($C::TRIGGERS as $k => $t)@unless (in_array($k, $C::DATED, true))<option value="{{ $k }}">{{ ucfirst($t['label']) }}{{ $t['param'] ? '…' : '' }}</option>@endunless @endforeach</optgroup>
                                <optgroup label="On a date (checked hourly)">@foreach ($C::DATED as $k)<option value="{{ $k }}">{{ ucfirst($C::TRIGGERS[$k]['label']) }}{{ $C::TRIGGERS[$k]['param'] ? '…' : '' }}</option>@endforeach</optgroup>
                            </select>
                            @if ($param = $C::TRIGGERS[$form['trigger']]['param'] ?? null)
                                <label for="a-param" class="pg-sr">Which</label>
                                <select id="a-param" wire:model.live="form.param" class="pg-select">@foreach ($C::PARAMS[$param] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                            @endif
                        </div>
                    </li>

                    <li class="pg-auto-step">
                        <h2>Only if</h2>
                        @forelse ($form['conditions'] as $i => $cond)
                            <fieldset class="pg-auto-cond" wire:key="cond-{{ $i }}-{{ $cond['field'] }}">
                                <legend>{{ ucfirst($C::CONDITIONS[$subject][$cond['field']][0] ?? $cond['field']) }}</legend>
                                <div class="pg-auto-checks">@foreach ($C::CONDITIONS[$subject][$cond['field']][1] ?? [] as $v => $l)<label class="pg-radio"><input type="checkbox" wire:model.live="form.conditions.{{ $i }}.values" value="{{ $v }}"> {{ $l }}</label>@endforeach</div>
                                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="removeCondition({{ $i }})">Remove</button>
                            </fieldset>
                        @empty
                            <p class="pg-empty">Always, whenever it happens.</p>
                        @endforelse
                        @if ($C::CONDITIONS[$subject] ?? [])
                            <div class="pg-auto-row">@foreach ($C::CONDITIONS[$subject] as $f => $c)<button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="addCondition('{{ $f }}')">Only if {{ $c[0] }}…</button>@endforeach</div>
                        @endif
                    </li>

                    <li class="pg-auto-step">
                        <h2>Then</h2>
                        @foreach ($form['actions'] as $i => $a)
                            <div class="pg-auto-action" wire:key="act-{{ $i }}-{{ $a['type'] }}">
                                <div class="pg-auto-action-head">
                                    <b>{{ $i + 1 }}. {{ $C::ACTIONS[$a['type']]['label'] ?? $a['type'] }}</b>
                                    <span class="pg-widget-tools">
                                        <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="moveAction({{ $i }}, -1)" @disabled($i === 0) aria-label="Do step {{ $i + 1 }} earlier"><span class="material-symbols-rounded" aria-hidden="true">arrow_upward</span></button>
                                        <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="moveAction({{ $i }}, 1)" @disabled($loop->last) aria-label="Do step {{ $i + 1 }} later"><span class="material-symbols-rounded" aria-hidden="true">arrow_downward</span></button>
                                        <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="removeAction({{ $i }})">Remove</button>
                                    </span>
                                </div>
                                @switch($a['type'])
                                    @case('notify')
                                        <div class="pg-auto-checks">@foreach ($C::RECIPIENTS as $v => $l)<label class="pg-radio"><input type="checkbox" wire:model.live="form.actions.{{ $i }}.to" value="{{ $v }}"> {{ $v === 'subject_person' ? $C::SUBJECT_PERSON[$subject] : $l }}</label>@endforeach</div>
                                        <label for="a-note-{{ $i }}" class="pg-label">Note to add <span class="pg-muted">(optional)</span></label>
                                        <input id="a-note-{{ $i }}" type="text" wire:model.live.debounce.500ms="form.actions.{{ $i }}.note" maxlength="300" class="pg-input">
                                        @break
                                    @case('escalate')
                                        <p class="pg-help">{{ $subject === 'task' ? 'Makes the task urgent and tells the project owner.' : 'Escalates it to the project owner, as the Escalate button does, unless it is already escalated.' }}</p>
                                        @break
                                    @case('create_tasks')
                                        <label for="a-titles-{{ $i }}" class="pg-label">Tasks, one per line</label>
                                        <textarea id="a-titles-{{ $i }}" wire:model.live.debounce.500ms="form.actions.{{ $i }}.titles" rows="3" class="pg-textarea"></textarea>
                                        <div class="pg-form-row pg-form-row--3">
                                            <div class="pg-field"><label for="a-for-{{ $i }}" class="pg-label">For</label><select id="a-for-{{ $i }}" wire:model.live="form.actions.{{ $i }}.assign" class="pg-select">@foreach ($C::ASSIGNEES as $v => $l)<option value="{{ $v }}">{{ $v === 'subject_person' ? $C::SUBJECT_PERSON[$subject] : $l }}</option>@endforeach</select></div>
                                            <div class="pg-field"><label for="a-due-{{ $i }}" class="pg-label">Due, days later</label><input id="a-due-{{ $i }}" type="number" min="0" max="365" wire:model.live.debounce.500ms="form.actions.{{ $i }}.due_days" class="pg-input" placeholder="No date"></div>
                                            <div class="pg-field"><label for="a-pri-{{ $i }}" class="pg-label">Priority</label><select id="a-pri-{{ $i }}" wire:model.live="form.actions.{{ $i }}.priority" class="pg-select">@foreach (App\Services\ProjectWork::PRIORITIES as $p)<option value="{{ $p }}">{{ ucfirst($p) }}</option>@endforeach</select></div>
                                        </div>
                                        @break
                                    @case('set_priority')
                                        <label for="a-sp-{{ $i }}" class="pg-sr">Priority</label>
                                        <select id="a-sp-{{ $i }}" wire:model.live="form.actions.{{ $i }}.priority" class="pg-select" style="max-width:200px">@foreach (App\Services\ProjectWork::PRIORITIES as $p)<option value="{{ $p }}">{{ ucfirst($p) }}</option>@endforeach</select>
                                        @break
                                    @case('add_label')
                                        <label for="a-lb-{{ $i }}" class="pg-sr">Label</label>
                                        <input id="a-lb-{{ $i }}" type="text" wire:model.live.debounce.500ms="form.actions.{{ $i }}.label" maxlength="40" class="pg-input" style="max-width:280px" placeholder="Needs review">
                                        @break
                                    @case('assign')
                                        <p class="pg-help">Gives the task to the project owner.</p>
                                        @break
                                    @case('start_report')
                                        <p class="pg-help">Starts a status report draft for the project, covering the days since its last report, for the project's manager to finish and publish. If a draft is already open, it is left as it is.</p>
                                        @break
                                    @case('wait')
                                        <div class="pg-auto-row">
                                            <label for="a-wait-{{ $i }}" class="pg-label" style="margin:0">Days</label>
                                            <input id="a-wait-{{ $i }}" type="number" min="1" max="30" wire:model.live.debounce.500ms="form.actions.{{ $i }}.days" class="pg-input" style="width:90px">
                                            <label class="pg-radio"><input type="checkbox" wire:model.live="form.actions.{{ $i }}.recheck"> Stop if it no longer applies by then</label>
                                        </div>
                                        <p class="pg-help">The steps after this one run when the wait is over, in working hours. With the box ticked they are skipped if, by then, the task is done, the risk closed, the milestone complete or the like.</p>
                                        @break
                                @endswitch
                            </div>
                        @endforeach
                        @if (count($form['actions']) < $C::MAX_ACTIONS)
                            <div class="pg-auto-row">
                                <label for="a-new" class="pg-sr">Add a step</label>
                                <select id="a-new" wire:model="newAction" class="pg-select" style="width:auto">
                                    @foreach ($C::ACTIONS as $k => $act)@if ($act['subjects'] === null || in_array($subject, $act['subjects'], true))<option value="{{ $k }}">{{ $act['label'] }}</option>@endif @endforeach
                                </select>
                                <button type="button" class="pg-btn pg-btn--secondary pg-btn--sm" wire:click="addAction">Add a step</button>
                            </div>
                        @endif
                    </li>
                </ol>

                @foreach (['name', 'project_id', 'trigger', 'conditions', 'actions', 'rule'] as $f)@error($f)<p class="pg-error" role="alert">{{ $message }}</p>@enderror @endforeach
                <div class="pg-actions" style="justify-content:space-between;align-items:center">
                    <span class="pg-help">{{ $editId ? 'Changes apply from the next event. It runs as you from now on.' : 'Saved switched off. Read what it would have done below, then switch it on.' }}</span>
                    <span class="pg-actions"><button type="button" class="pg-btn pg-btn--ghost" wire:click="$set('building', false)">Cancel</button><button type="submit" class="pg-btn pg-btn--primary" @disabled($draftError !== null)>Save rule</button></span>
                </div>
            </form>

            @if ($preview)
                @include('partials.programme.automation-preview', ['pv' => $preview, 'h' => 'What it would have done in the last 30 days'])
            @endif
        @elseif ($selected)
            <div class="pg-titlerow">
                <div style="min-width:0">
                    <h1 class="pg-title">{{ $selected->name }}<span class="pg-pill {{ $selected->active ? 'pg-pill--positive' : '' }}">{{ $selected->active ? 'On' : 'Off' }}</span></h1>
                    <p class="pg-finding">{{ $sentence }}</p>
                    <p class="pg-help">Runs as {{ $selected->creator?->name ?? 'nobody: its maker has left' }}{{ $selected->activated_at ? '; last switched on '.$selected->activated_at->format('j M Y') : '' }}.</p>
                </div>
                @if ($canManage)
                    <div class="pg-actions" style="align-items:center">
                        <button type="button" class="pg-btn {{ $selected->active ? 'pg-btn--secondary' : 'pg-btn--primary' }}" wire:click="toggle({{ $selected->id }})">{{ $selected->active ? 'Switch off' : 'Switch on' }}</button>
                        <button type="button" class="pg-btn pg-btn--ghost" wire:click="edit({{ $selected->id }})">Change</button>
                        <button type="button" class="pg-btn pg-btn--ghost" wire:click="remove({{ $selected->id }})" wire:confirm="Delete this rule and its history? What it already did stays done.">Delete</button>
                    </div>
                @endif
            </div>
            @error('rule')<p class="pg-error" role="alert">{{ $message }}</p>@enderror

            @include('partials.programme.automation-preview', ['pv' => $selectedPreview, 'h' => $selected->active ? 'Matches in the last 30 days' : 'What it would have done in the last 30 days'])

            <section class="pg-card" aria-labelledby="runs-h" style="margin-top:var(--pg-gap)">
                <div class="pg-card-head"><h2 id="runs-h">Runs</h2><small>{{ $runs->count() ? 'latest '.$runs->count() : '' }}</small></div>
                @error('run')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
                @forelse ($runs as $run)
                    <div class="pg-row pg-auto-run" wire:key="run-{{ $run->id }}">
                        <div style="min-width:0">
                            <div>{{ $run->what ?? 'A run' }}</div>
                            <ul class="pg-auto-steps">@foreach ($run->steps as $s)<li class="{{ $s['ok'] ? '' : 'pg-neg' }}">{{ $s['summary'] }}</li>@endforeach</ul>
                            <div class="pg-sub">{{ $run->updated_at->format('j M, H:i') }}{{ $run->attempts > 1 ? ', '.$run->attempts.' attempts' : '' }}</div>
                        </div>
                        <span class="pg-actions" style="align-items:center">
                            <span class="pg-pill {{ $statusPill[$run->status] ?? '' }}">{{ App\Models\AutomationRun::STATUSES[$run->status] ?? $run->status }}</span>
                            @if ($run->status === 'failed' && $canManage)<button type="button" class="pg-btn pg-btn--secondary pg-btn--sm" wire:click="retry({{ $run->id }})">Retry</button>@endif
                        </span>
                    </div>
                @empty
                    <p class="pg-empty">{{ $selected->active ? 'It has not run yet. It runs the next time this happens.' : 'Switched off, so it has not run.' }}</p>
                @endforelse
            </section>
        @else
            <div class="pg-titlerow"><div><h1 class="pg-title">Automations</h1>
                <p class="pg-finding">Rules that act when something happens in a project: tell the right people, escalate, add the tasks that always follow. Each reads as a sentence and shows what it would have done before you switch it on.</p></div></div>
            @if ($oversees || $scopes->isNotEmpty())
                <section class="pg-card" aria-labelledby="tpl-h">
                    <div class="pg-card-head"><h2 id="tpl-h">Templates</h2><small>open one to adjust it before saving</small></div>
                    @foreach ($C::TEMPLATES as $key => $t)
                        @if ($t['trigger'] !== 'budget_threshold' || $oversees || $scopes->contains(fn ($p) => $p->moneyVisibleTo(auth()->user())))
                            <div class="pg-row" wire:key="tpl-{{ $key }}">
                                <div style="min-width:0"><b class="pg-auto-tname">{{ $t['name'] }}</b><div class="pg-sub">{{ $templates[$key] }}</div></div>
                                <button type="button" class="pg-btn pg-btn--secondary pg-btn--sm" wire:click="startNew('{{ $key }}')">Use this</button>
                            </div>
                        @endif
                    @endforeach
                    <p class="pg-help">Rules on dates are checked every hour from 07:00 to 19:00 South African time. Approval steps and branches come next.</p>
                </section>
            @else
                <p class="pg-empty">Rules are made by the people who manage a project, and by the business owner and admins.</p>
            @endif
        @endif
    </section>
</div>
