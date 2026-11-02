<div>
    @php
        $r = fn (int $c) => App\Support\Money::rand($c);
        $t = $data['total'];
        $lines = $data['lines'];
        $openRow = $openLine === '' ? null : collect($lines)->first(fn ($x) => (string) $x['line']->id === $openLine);
    @endphp

    <p class="pg-finding" style="margin:0 0 var(--pg-gap)">
        @if ($lines === [])
            No budget yet. Add it line by line (labour, contractors, materials, equipment, operational, contingency) so spending and the forecast can be measured against it.
        @else
            <b class="pg-money">{{ $r($t['actual'] + $t['open']) }}</b> of {{ $r($t['budget']) }} spent or committed{{ $t['used'] !== null ? ' ('.$t['used'].'%)' : '' }}.
            Forecast at completion {{ $r($t['forecast']) }}, <span class="{{ $t['variance'] < 0 ? 'pg-neg' : '' }}">{{ $t['variance'] < 0 ? $r(-$t['variance']).' over budget' : ($t['variance'] === 0 ? 'exactly on budget' : $r($t['variance']).' under budget') }}</span>.
            @if ($data['revised'] !== 0) The budget has been revised by {{ App\Support\Money::rand($data['revised'], true) }} since it was set.@endif
        @endif
    </p>

    @if ($risks)
        <section class="pg-card" style="margin-bottom:var(--pg-gap)" aria-labelledby="mr-h">
            <div class="pg-card-head"><h2 id="mr-h">Budget risks</h2><small>{{ count($risks) }}</small></div>
            @foreach ($risks as $risk)
                <div class="pg-row"><span>{{ $risk['text'] }}</span><span class="pg-pill {{ $risk['level'] === 'negative' ? 'pg-pill--negative' : 'pg-pill--warning' }}">{{ $risk['level'] === 'negative' ? 'Over' : 'Watch' }}</span></div>
            @endforeach
        </section>
    @endif

    <section class="pg-table-wrap" aria-labelledby="ml-h">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 16px 4px;flex-wrap:wrap">
            <h2 id="ml-h" style="font-size:14px;font-weight:600;margin:0">Cost report</h2>
            @if ($canEdit)
                <details class="pg-menu" style="margin-left:auto">
                    <summary class="pg-btn pg-btn--secondary pg-btn--sm">Add a budget line</summary>
                    <div class="pg-menu-panel" style="width:340px;padding:12px">
                        <form wire:submit="addLine" class="pg-form" style="gap:8px">
                            <label for="bl-cat" class="pg-label" style="margin:0">Category</label>
                            <select id="bl-cat" wire:model="newCategory" class="pg-select">
                                @foreach (App\Models\BudgetLine::CATEGORIES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                            <label for="bl-name" class="pg-label" style="margin:0">Line</label>
                            <input id="bl-name" type="text" wire:model="newName" maxlength="160" class="pg-input" placeholder="Steel and fixings" required>
                            @error('name')<p class="pg-error">{{ $message }}</p>@enderror
                            <label for="bl-amount" class="pg-label" style="margin:0">Budget, in rand</label>
                            <input id="bl-amount" type="text" inputmode="decimal" wire:model="newAmount" class="pg-input" placeholder="250 000">
                            @error('budget')<p class="pg-error">{{ $message }}</p>@enderror
                            <button type="submit" class="pg-btn pg-btn--primary">Add the line</button>
                        </form>
                    </div>
                </details>
            @endif
        </div>
        @if ($lines === [])
            <p class="pg-empty" style="padding:8px 16px 16px">The cost report lists each budget line with what is spent, committed and still to come, against its budget.</p>
        @else
            <table class="pg-table pg-table--money">
                <thead>
                    <tr><th scope="col">Line</th><th scope="col" class="r">Budget</th><th scope="col" class="r">Actual</th><th scope="col" class="r">Committed</th><th scope="col" class="r">To complete</th><th scope="col" class="r">Forecast</th><th scope="col" class="r">Variance</th><th scope="col" class="r">Used</th></tr>
                </thead>
                <tbody>
                    @foreach ($lines as $row)
                        @php $l = $row['line']; @endphp
                        <tr wire:key="bl-{{ $l->id }}" class="{{ $openLine === (string) $l->id ? 'is-open' : '' }}">
                            <td class="pg-name"><button type="button" class="pg-linkbtn" style="margin:0;text-decoration:none;font-weight:500" wire:click="$set('openLine', '{{ $openLine === (string) $l->id ? '' : $l->id }}')">{{ $l->name }}</button><span class="pg-sub">{{ App\Models\BudgetLine::CATEGORIES[$l->category] ?? 'Other' }}</span></td>
                            <td data-label="Budget" class="r">{{ $r($row['budget']) }}@if ($canEdit)<button type="button" class="pg-linkbtn pg-sub" wire:click="startRevise({{ $l->id }})" aria-label="Revise the budget of {{ $l->name }}">Revise</button>@endif</td>
                            <td data-label="Actual" class="r">{{ $r($row['actual']) }}@if ($row['fromTime'])<span class="pg-sub">incl. {{ $r($row['fromTime']) }} from time</span>@endif</td>
                            <td data-label="Committed" class="r">{{ $r($row['open']) }}</td>
                            <td data-label="To complete" class="r">{{ $r($row['toComplete']) }}<span class="pg-sub">{{ $row['toCompleteSet'] ? 'Set' : 'What is left' }}@if ($canEdit) <button type="button" class="pg-linkbtn" wire:click="startForecast({{ $l->id }})" aria-label="Set the cost still to come on {{ $l->name }}">Change</button>@endif</span></td>
                            <td data-label="Forecast" class="r">{{ $r($row['forecast']) }}</td>
                            <td data-label="Variance" class="r {{ $row['variance'] < 0 ? 'pg-neg' : '' }}">{{ $row['variance'] < 0 ? '−'.$r(-$row['variance']) : $r($row['variance']) }}</td>
                            <td data-label="Used" class="r">{{ $row['used'] !== null ? $row['used'].'%' : '–' }}</td>
                        </tr>
                    @endforeach
                    <tr class="pg-total">
                        <th scope="row">Total</th>
                        <td data-label="Budget" class="r">{{ $r($t['budget']) }}</td>
                        <td data-label="Actual" class="r">{{ $r($t['actual']) }}</td>
                        <td data-label="Committed" class="r">{{ $r($t['open']) }}</td>
                        <td data-label="To complete" class="r">{{ $r($t['toComplete']) }}</td>
                        <td data-label="Forecast" class="r">{{ $r($t['forecast']) }}</td>
                        <td data-label="Variance" class="r {{ $t['variance'] < 0 ? 'pg-neg' : '' }}">{{ $t['variance'] < 0 ? '−'.$r(-$t['variance']) : $r($t['variance']) }}</td>
                        <td data-label="Used" class="r">{{ $t['used'] !== null ? $t['used'].'%' : '–' }}</td>
                    </tr>
                </tbody>
            </table>
        @endif

        @if ($revising)
            <form wire:submit="revise" class="pg-form pg-inline-form" aria-label="Revise a budget line">
                <div class="pg-form-row pg-form-row--3">
                    <div class="pg-field"><label for="rv-to" class="pg-label">New budget, in rand</label><input id="rv-to" type="text" inputmode="decimal" wire:model="reviseTo" class="pg-input">@error('budget')<p class="pg-error">{{ $message }}</p>@enderror</div>
                    <div class="pg-field"><label for="rv-why" class="pg-label">Why it changes</label><input id="rv-why" type="text" wire:model="reviseReason" maxlength="500" class="pg-input" placeholder="Second loading bay added">@error('reason')<p class="pg-error">{{ $message }}</p>@enderror</div>
                    <div class="pg-field"><label for="rv-cr" class="pg-label">From an approved change (optional)</label>
                        <select id="rv-cr" wire:model="reviseChange" class="pg-select"><option value="">None</option>@foreach ($changes as $c)<option value="{{ $c->id }}">{{ $c->ref() }} {{ Str::limit($c->title, 40) }}{{ $c->cost_cents ? ', '.App\Support\Money::rand($c->cost_cents, true) : '' }}</option>@endforeach</select>
                        @error('change_id')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="pg-actions"><button type="submit" class="pg-btn pg-btn--primary pg-btn--sm">Revise the budget</button><button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('revising', null)">Cancel</button></div>
            </form>
        @endif
        @if ($forecasting)
            <form wire:submit="saveForecast" class="pg-form pg-inline-form" aria-label="Set the cost still to come">
                <div class="pg-field" style="max-width:360px"><label for="fc-amt" class="pg-label">Cost still to come on this line, in rand</label><input id="fc-amt" type="text" inputmode="decimal" wire:model="forecastAmount" class="pg-input" placeholder="Leave empty to use what is left of the budget">@error('to_complete')<p class="pg-error">{{ $message }}</p>@enderror</div>
                <div class="pg-actions"><button type="submit" class="pg-btn pg-btn--primary pg-btn--sm">Save</button><button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('forecasting', null)">Cancel</button></div>
            </form>
        @endif
    </section>

    @php
        $x = fn (?float $v) => $v === null ? '–' : number_format($v, 2);
        $signed = fn (int $c) => $c < 0 ? '−'.$r(-$c) : $r($c);
    @endphp
    <div class="pg-grid" style="margin-top:var(--pg-gap)">
        <section class="pg-card pg-s8" aria-labelledby="ev-h">
            <div class="pg-card-head">
                <h2 id="ev-h">Earned value</h2>
                <span style="display:flex;gap:6px;align-items:center"><label for="ev-asof" class="pg-help" style="margin:0">As of</label><input id="ev-asof" type="date" wire:model.live.debounce.500ms="asOf" value="{{ $ev['asOf']->toDateString() }}" class="pg-input" style="width:auto;min-height:30px"></span>
            </div>
            @if ($ev['bac'] === 0 || $ev['tasks'] === 0)
                <p class="pg-empty">Earned value needs a budget and tasks to measure progress against.</p>
            @else
                <p style="margin:0 0 10px">
                    @if ($ev['cpi'] !== null)For each rand spent, the project has earned {{ number_format($ev['cpi'] * 100) }}c of planned work (CPI {{ $x($ev['cpi']) }}). @endif
                    @if ($ev['spi'] !== null)It has done {{ number_format($ev['spi'] * 100) }}% of the work planned to {{ $ev['asOf']->format('j M') }} (SPI {{ $x($ev['spi']) }}). @endif
                    @if ($ev['eac'] !== null)If costs keep running this way it finishes at {{ $r($ev['eac']) }}, <span class="{{ $ev['vac'] < 0 ? 'pg-neg' : '' }}">{{ $ev['vac'] < 0 ? $r(-$ev['vac']).' over' : $r($ev['vac']).' under' }}</span> the budget.@endif
                </p>
                <table class="pg-table pg-table--ev" style="min-width:0">
                    <thead><tr><th scope="col">Measure</th><th scope="col" class="r">Value</th><th scope="col">How it is worked out</th></tr></thead>
                    <tbody>
                        <tr><td>Budget at completion (BAC)</td><td data-label="Value" class="r">{{ $r($ev['bac']) }}</td><td data-label="How">Total budget</td></tr>
                        <tr><td>Planned value (PV)</td><td data-label="Value" class="r">{{ $r($ev['pv']) }}</td><td data-label="How">Budget for the work planned by {{ $ev['asOf']->format('j M') }}</td></tr>
                        <tr><td>Earned value (EV)</td><td data-label="Value" class="r">{{ $r($ev['ev']) }}</td><td data-label="How">Budget for the work done: not started 0%, started 50%, done 100%</td></tr>
                        <tr><td>Actual cost (AC)</td><td data-label="Value" class="r">{{ $r($ev['ac']) }}</td><td data-label="How">Actual costs, with approved time at rates</td></tr>
                        <tr><td>Schedule variance (SV) and index (SPI)</td><td data-label="Value" class="r {{ $ev['sv'] < 0 ? 'pg-neg' : '' }}">{{ $signed($ev['sv']) }}, {{ $x($ev['spi']) }}</td><td data-label="How">EV minus PV, and EV divided by PV; below 1 is behind</td></tr>
                        <tr><td>Cost variance (CV) and index (CPI)</td><td data-label="Value" class="r {{ $ev['cv'] < 0 ? 'pg-neg' : '' }}">{{ $signed($ev['cv']) }}, {{ $x($ev['cpi']) }}</td><td data-label="How">EV minus AC, and EV divided by AC; below 1 is over cost</td></tr>
                        <tr><td>Estimate at completion (EAC)</td><td data-label="Value" class="r">{{ $ev['eac'] === null ? '–' : $r($ev['eac']) }}</td><td data-label="How">BAC divided by CPI</td></tr>
                        <tr><td>Estimate to complete (ETC)</td><td data-label="Value" class="r">{{ $ev['etc'] === null ? '–' : $r($ev['etc']) }}</td><td data-label="How">EAC minus AC</td></tr>
                        <tr><td>Variance at completion (VAC)</td><td data-label="Value" class="r {{ ($ev['vac'] ?? 0) < 0 ? 'pg-neg' : '' }}">{{ $ev['vac'] === null ? '–' : $signed($ev['vac']) }}</td><td data-label="How">BAC minus EAC</td></tr>
                        <tr><td>To-complete performance index (TCPI)</td><td data-label="Value" class="r">{{ $x($ev['tcpi']) }}</td><td data-label="How">(BAC minus EV) divided by (BAC minus AC): the cost efficiency needed from now to land on budget</td></tr>
                    </tbody>
                </table>
                <p class="pg-help" style="margin:10px 0 0">Planned dates come from {{ $ev['baseline'] ? 'baseline '.$ev['baseline'] : 'the current task dates (no baseline is set)' }}. {{ $ev['coverage'] }}% of the work, by {{ $ev['weighted'] ? 'estimate' : 'task count, since no task has an estimate' }}, has dates to plan against; {{ $ev['tasks'] - $ev['dated'] }} of {{ $ev['tasks'] }} {{ Str::plural('task', $ev['tasks']) }} {{ $ev['tasks'] - $ev['dated'] === 1 ? 'has' : 'have' }} none and add nothing to PV. An index with nothing to divide by shows as a dash.</p>
            @endif
        </section>

        <section class="pg-card pg-s4" aria-labelledby="lab-h">
            <div class="pg-card-head"><h2 id="lab-h">Labour from approved time</h2></div>
            <dl class="pg-kv">
                <dt>Approved hours</dt><dd>{{ App\Support\Duration::format($labour['minutes']) }}</dd>
                <dt>At rates</dt><dd>{{ $r($labour['cents']) }}</dd>
                @if ($labour['unpriced'])<dt>No rate set</dt><dd class="pg-neg">{{ App\Support\Duration::format($labour['unpriced']) }} not costed</dd>@endif
            </dl>
            @if ($seesRates && count($labour['people']) > 0)
                <dl class="pg-kv" style="margin-top:10px">@foreach ($labour['people'] as $p)<dt>{{ $p['name'] }}</dt><dd>{{ App\Support\Duration::format($p['minutes']) }}, {{ $r($p['cents']) }}{{ $p['unpriced'] ? ' (some without a rate)' : '' }}</dd>@endforeach</dl>
            @endif
            <div class="pg-field" style="margin-top:12px">
                <label for="lab-line" class="pg-label">Charge it to</label>
                <select id="lab-line" class="pg-select" wire:change="chargeTime($event.target.value)" @disabled(! $canEdit)>
                    <option value="" @selected(! $project->labour_line_id)>Not charged to the budget</option>
                    @foreach ($lines as $row)<option value="{{ $row['line']->id }}" @selected($project->labour_line_id === $row['line']->id)>{{ $row['line']->name }}</option>@endforeach
                </select>
                @error('labour_line_id')<p class="pg-error">{{ $message }}</p>@enderror
            </div>
            <p class="pg-help" style="margin:8px 0 0">Approved weeks only, at each person's hourly rate on the day worked. Rates are set by the business owner or an admin on the Workload page.</p>
        </section>
    </div>

    <div class="pg-grid" style="margin-top:var(--pg-gap)">
        <section class="pg-table-wrap pg-s8" aria-labelledby="ci-h">
            <div class="pg-filters" style="padding:12px 16px 4px;margin:0">
                <h2 id="ci-h" style="font-size:14px;font-weight:600;margin:0 auto 0 0">{{ $openRow ? 'Costs on '.$openRow['line']->name : 'Costs' }}</h2>
                @if ($openRow)<button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('openLine', '')">All lines</button>@endif
                <label for="ci-kind" class="pg-sr">Which costs</label>
                <select id="ci-kind" wire:model.live="kindFilter" class="pg-select" style="width:auto">
                    <option value="">Commitments and actuals</option>
                    @foreach (App\Models\CostItem::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}s</option>@endforeach
                </select>
            </div>
            @error('cost')<p class="pg-error" style="padding:0 16px">{{ $message }}</p>@enderror
            @if ($items->isEmpty())
                <p class="pg-empty" style="padding:8px 16px 16px">Orders and contracts placed (commitments) and invoices and expenses (actuals) appear here.</p>
            @else
                <table class="pg-table pg-table--costs" style="min-width:0">
                    <thead><tr><th scope="col">Cost</th><th scope="col">Date</th><th scope="col">Kind</th><th scope="col" class="r">Amount</th><th scope="col"><span class="pg-sr">Remove</span></th></tr></thead>
                    <tbody>
                        @foreach ($items as $i)
                            <tr wire:key="ci-{{ $i->id }}">
                                <td class="pg-name">{{ $i->description }}<span class="pg-sub">{{ implode(', ', array_filter([$i->line?->name, $i->supplier, $i->reference])) }}{{ $i->settles ? '; settles '.($i->settles->reference ?? $i->settles->description) : '' }}</span></td>
                                <td data-label="Date">{{ $i->dated->format('j M Y') }}</td>
                                <td data-label="Kind">{{ App\Models\CostItem::KINDS[$i->kind] }}</td>
                                <td data-label="Amount" class="r">{{ App\Support\Money::rand($i->amount_cents) }}</td>
                                <td data-label="Status">@if ($canEdit)<button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="removeCost({{ $i->id }})" wire:confirm="Remove this cost?">Remove</button>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <aside class="pg-s4" style="display:grid;gap:var(--pg-gap);align-content:start">
            @if ($canEdit && $lines !== [])
                <section class="pg-card" aria-labelledby="rc-h">
                    <div class="pg-card-head"><h2 id="rc-h">Record a cost</h2></div>
                    <form wire:submit="addCost" class="pg-form" style="gap:8px">
                        <div class="pg-seg" role="group" aria-label="Kind of cost" style="justify-self:start">
                            <button type="button" wire:click="$set('costKind', 'actual')" aria-pressed="{{ $costKind === 'actual' ? 'true' : 'false' }}">Actual</button>
                            <button type="button" wire:click="$set('costKind', 'commitment')" aria-pressed="{{ $costKind === 'commitment' ? 'true' : 'false' }}">Commitment</button>
                        </div>
                        <p class="pg-help" style="margin:0">{{ $costKind === 'actual' ? 'An invoice or expense. A credit note is a negative amount.' : 'An order or contract placed, not yet invoiced.' }}</p>
                        <label for="rc-line" class="pg-label" style="margin:0">Budget line</label>
                        <select id="rc-line" wire:model.live="costLine" class="pg-select">
                            @foreach ($lines as $row)<option value="{{ $row['line']->id }}">{{ $row['line']->name }}</option>@endforeach
                        </select>
                        @error('budget_line_id')<p class="pg-error">{{ $message }}</p>@enderror
                        <label for="rc-desc" class="pg-label" style="margin:0">What it is for</label>
                        <input id="rc-desc" type="text" wire:model="description" maxlength="255" class="pg-input" required>
                        @error('description')<p class="pg-error">{{ $message }}</p>@enderror
                        <div class="pg-form-row">
                            <div class="pg-field"><label for="rc-sup" class="pg-label">Supplier</label><input id="rc-sup" type="text" wire:model="supplier" maxlength="160" class="pg-input"></div>
                            <div class="pg-field"><label for="rc-ref" class="pg-label">{{ $costKind === 'actual' ? 'Invoice no.' : 'Order no.' }}</label><input id="rc-ref" type="text" wire:model="reference" maxlength="80" class="pg-input"></div>
                        </div>
                        <div class="pg-form-row">
                            <div class="pg-field"><label for="rc-amt" class="pg-label">Amount, rand</label><input id="rc-amt" type="text" inputmode="decimal" wire:model="amount" class="pg-input" placeholder="45 000">@error('amount_cents')<p class="pg-error">{{ $message }}</p>@enderror</div>
                            <div class="pg-field"><label for="rc-date" class="pg-label">Date</label><input id="rc-date" type="date" wire:model="dated" max="{{ today()->toDateString() }}" class="pg-input">@error('dated')<p class="pg-error">{{ $message }}</p>@enderror</div>
                        </div>
                        @if ($costKind === 'actual' && $openCommitments->isNotEmpty())
                            <label for="rc-settles" class="pg-label" style="margin:0">Settles a commitment (optional)</label>
                            <select id="rc-settles" wire:model="settles" class="pg-select">
                                <option value="">No</option>
                                @foreach ($openCommitments as $c)<option value="{{ $c->id }}">{{ Str::limit($c->description, 30) }}{{ $c->reference ? ', '.$c->reference : '' }}, {{ App\Support\Money::rand($c->amount_cents) }}</option>@endforeach
                            </select>
                            @error('settles_id')<p class="pg-error">{{ $message }}</p>@enderror
                        @endif
                        <div><button type="submit" class="pg-btn pg-btn--primary">Record it</button></div>
                    </form>
                </section>
            @endif

            @if ($openRow)
                <section class="pg-card" aria-labelledby="bh-h">
                    <div class="pg-card-head"><h2 id="bh-h">Budget history</h2><small>{{ $openRow['line']->name }}</small></div>
                    @forelse ($history as $h)
                        <div class="pg-act"><time datetime="{{ $h->created_at->toIso8601String() }}">{{ $h->created_at->format('j M Y') }}</time><span><b style="font-weight:500">{{ App\Support\Money::rand($h->from_cents) }} to {{ App\Support\Money::rand($h->to_cents) }}</b>: {{ $h->reason }}{{ $h->change ? ' ('.$h->change->ref().')' : '' }}<span class="pg-sub" style="display:block">{{ $h->creator?->name ?? 'Someone' }}</span></span></div>
                    @empty
                        <p class="pg-empty">No budget set on this line yet.</p>
                    @endforelse
                </section>
            @endif
        </aside>
    </div>

    <p class="pg-help" style="margin-top:12px">
        Forecast at completion = actual + open commitments + cost to complete. Open commitments are orders less the invoices that settle them. Cost to complete is what is left of the budget unless someone sets it. Variance = budget − forecast. Costs are recorded here until Dot.Finance can share actuals; only the project's owner and managers, the business owner and admins see this page.
    </p>
</div>
