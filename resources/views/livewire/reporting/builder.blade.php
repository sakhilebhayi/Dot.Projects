<div class="pg-rep">
    @php
        $cols = $def['columns'];
        $shownRows = array_slice($result['rows'], 0, App\Livewire\Reporting\Builder::SHOWN);
        $numeric = ['money', 'int', 'percent', 'ratio', 'hours', 'days'];
        $chosenCount = count($projects);
    @endphp

    <aside class="pg-rep-side" aria-label="Reports">
        <div class="pg-rep-pick">
            <label for="rep-type" class="pg-label">Report</label>
            <select id="rep-type" class="pg-select" x-on:change="$wire.choose($event.target.value)">
                @foreach (App\Services\Reporting::REPORTS as $key => $r)<option value="{{ $key }}" @selected($key === $type)>{{ $r['label'] }}</option>@endforeach
            </select>
        </div>
        <h2 class="pg-rep-h">Reports</h2>
        <ul class="pg-rep-list">
            @foreach (App\Services\Reporting::REPORTS as $key => $r)
                <li><button type="button" wire:click="choose('{{ $key }}')" @if ($key === $type && $savedId === '') aria-current="true" @endif>{{ $r['label'] }}</button></li>
            @endforeach
        </ul>
        <h2 class="pg-rep-h">Saved</h2>
        @if ($saved->isEmpty())
            <p class="pg-empty pg-rep-none">Reports you save, and those shared with the business, are kept here.</p>
        @else
            <ul class="pg-rep-list">
                @foreach ($saved as $s)
                    <li wire:key="sv-{{ $s->id }}"><button type="button" wire:click="open({{ $s->id }})" @if ((string) $s->id === $savedId) aria-current="true" @endif>{{ $s->name }}<span>{{ $s->user_id === auth()->id() ? ($s->shared ? 'Shared by you' : 'Only you') : 'Shared by '.$s->user->name }}</span></button></li>
                @endforeach
            </ul>
        @endif
    </aside>

    <section class="pg-rep-main" aria-labelledby="rep-title">
        <div class="pg-titlerow">
            <div style="min-width:0">
                <h1 id="rep-title" class="pg-title">{{ $current ? $current->name : $def['label'] }}@if ($current && $current->shared)<span class="pg-pill">Shared</span>@endif</h1>
                <p class="pg-finding">{{ $def['about'] }}@if ($current) <span class="pg-muted">A saved {{ mb_strtolower($def['label']) }} report{{ $dirty ? ', changed since it was saved' : '' }}.</span>@endif</p>
            </div>
            <div class="pg-actions pg-rep-actions">
                <span class="pg-rep-files" role="group" aria-label="Download">
                    @foreach (App\Services\ReportExport::FORMATS as $f => $label)
                        <a class="pg-btn pg-btn--secondary pg-btn--sm" href="{{ $exports[$f] }}" download>{{ $label }}</a>
                    @endforeach
                </span>
                @if ($current && $canManage)
                    <button type="button" class="pg-btn pg-btn--primary pg-btn--sm" wire:click="startSave">Save</button>
                    <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="startSave(true)">Save as new</button>
                @else
                    <button type="button" class="pg-btn pg-btn--primary pg-btn--sm" wire:click="startSave(true)">Save report</button>
                @endif
            </div>
        </div>

        @if ($saving)
            <form wire:submit="save" class="pg-card pg-rep-save">
                <div class="pg-field" style="flex:1 1 260px">
                    <label for="rep-name" class="pg-label">Name</label>
                    <input id="rep-name" type="text" wire:model="name" maxlength="120" class="pg-input" required autofocus>
                    @error('name')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <label class="pg-radio"><input type="checkbox" wire:model="shared"> Share with {{ auth()->user()->currentTeam->name }}</label>
                <span class="pg-actions">
                    @if ($current && $canManage)<button type="button" class="pg-btn pg-btn--danger pg-btn--sm" wire:click="removeSaved" wire:confirm="Delete this saved report? The data it reports on is not touched.">Delete</button>@endif
                    <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('saving', false)">Cancel</button>
                    <button type="submit" class="pg-btn pg-btn--primary pg-btn--sm">Save</button>
                </span>
                <p class="pg-help" style="flex-basis:100%">Sharing shares the settings, not the rows: each person sees only the projects, money and time they may see.</p>
            </form>
        @endif

        <div class="pg-filters pg-rep-filters">
            @unless ($type === 'utilisation')
                <label for="rep-scope" class="pg-sr">Which projects</label>
                <select id="rep-scope" wire:model.live="scope" class="pg-select">
                    @foreach (App\Services\Reporting::SCOPES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                </select>
                @if ($portfolios->isNotEmpty())
                    <label for="rep-pf" class="pg-sr">Portfolio</label>
                    <select id="rep-pf" wire:model.live="portfolio" class="pg-select">
                        <option value="">Every portfolio</option>
                        @foreach ($portfolios as $pf)<option value="{{ $pf->id }}">{{ $pf->name }}</option>@endforeach
                    </select>
                @endif
                <details class="pg-menu pg-rep-projects" wire:ignore.self>
                    <summary class="pg-chip">{{ $chosenCount ? $chosenCount.' '.Str::plural('project', $chosenCount).' chosen' : 'All projects' }}<span class="material-symbols-rounded" aria-hidden="true">expand_more</span></summary>
                    <div class="pg-menu-panel">
                        <p class="pg-menu-label">Only these projects</p>
                        <div class="pg-rep-checks">
                            @foreach ($choosable as $pr)<label class="pg-radio"><input type="checkbox" wire:model.live="projects" value="{{ $pr->id }}"> {{ $pr->name }}</label>@endforeach
                        </div>
                        @if ($chosenCount)<button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('projects', [])">Clear the choice</button>@endif
                    </div>
                </details>
            @endunless
            @if ($def['period'])
                <label for="rep-period" class="pg-sr">Period</label>
                <select id="rep-period" wire:model.live="period" class="pg-select">
                    @foreach (App\Services\TimeReport::PERIODS as $v => $l)@if ($v !== 'all')<option value="{{ $v }}">{{ $l }}</option>@endif @endforeach
                </select>
                @if ($period === 'custom')
                    <label for="rep-from" class="pg-sr">From</label><input id="rep-from" type="date" wire:model.live.debounce.400ms="from" class="pg-input pg-rep-date">
                    <label for="rep-to" class="pg-sr">To</label><input id="rep-to" type="date" wire:model.live.debounce.400ms="to" class="pg-input pg-rep-date">
                @endif
            @endif
            <details class="pg-menu pg-rep-colmenu" wire:ignore.self>
                <summary class="pg-chip">Columns, {{ count($config['columns']) }} of {{ count($cols) }}<span class="material-symbols-rounded" aria-hidden="true">expand_more</span></summary>
                <div class="pg-menu-panel">
                    <p class="pg-menu-label">Show</p>
                    <div class="pg-rep-checks">
                        @foreach ($cols as $key => $c)
                            <label class="pg-radio"><input type="checkbox" wire:model.live="columns" value="{{ $key }}" @disabled($loop->first)> {{ $c[0] }}</label>
                        @endforeach
                    </div>
                    <p class="pg-menu-label">Order</p>
                    <ol class="pg-rep-order">
                        @foreach ($config['columns'] as $i => $key)
                            <li wire:key="ord-{{ $key }}">
                                <span>{{ $cols[$key][0] }}</span>
                                @if ($i > 0)
                                    <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="move('{{ $key }}', -1)" @disabled($i === 1) aria-label="Move {{ $cols[$key][0] }} left"><span class="material-symbols-rounded" aria-hidden="true">arrow_back</span></button>
                                    <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="move('{{ $key }}', 1)" @disabled($loop->last) aria-label="Move {{ $cols[$key][0] }} right"><span class="material-symbols-rounded" aria-hidden="true">arrow_forward</span></button>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="resetColumns">Back to the usual columns</button>
                </div>
            </details>
        </div>

        <p class="pg-help pg-rep-about">{{ $about }}</p>
        @if ($result['notes'])
            <ul class="pg-rep-notes">@foreach ($result['notes'] as $note)<li>{{ $note }}</li>@endforeach</ul>
        @endif

        <div class="pg-table-wrap" wire:loading.class="pg-rep-busy">
            @if ($result['rows'] === [])
                <p class="pg-empty" style="padding:16px">Nothing matches these filters{{ $type === 'budget' ? ': no project you may see the money of has a budget yet' : '' }}.</p>
            @else
                <table class="pg-table pg-table--report">
                    <caption class="pg-sr">{{ $current ? $current->name : $def['label'] }}, sorted {{ $sort ? 'by '.$cols[$sort][0].($desc ? ', highest first' : ', lowest first') : 'in the report\'s order' }}</caption>
                    <thead>
                        <tr>
                            @foreach ($config['columns'] as $key)
                                <th scope="col" class="{{ in_array($cols[$key][1], $numeric, true) ? 'r' : '' }}" aria-sort="{{ $sort === $key ? ($desc ? 'descending' : 'ascending') : 'none' }}">
                                    <button type="button" class="pg-sortbtn" wire:click="sortBy('{{ $key }}')">{{ $cols[$key][0] }}@if ($sort === $key)<span class="material-symbols-rounded" aria-hidden="true">{{ $desc ? 'arrow_downward' : 'arrow_upward' }}</span>@endif</button>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($shownRows as $i => $row)
                            <tr>
                                @foreach ($config['columns'] as $key)
                                    @php
                                        $t = $cols[$key][1];
                                        $v = $row[$key] ?? null;
                                        $neg = in_array($key, ['variance', 'sv', 'cv', 'vac'], true) && is_int($v) && $v < 0;
                                    @endphp
                                    @if ($loop->first)
                                        <td class="pg-name">@if ($result['links'][$i] ?? null)<a href="{{ $result['links'][$i] }}">{{ App\Services\Reporting::show($v, $t) }}</a>@else{{ App\Services\Reporting::show($v, $t) }}@endif</td>
                                    @else
                                        <td data-label="{{ $cols[$key][0] }}" class="{{ in_array($t, $numeric, true) ? 'r' : '' }}{{ $neg ? ' pg-neg' : '' }}{{ $v === null ? ' pg-muted' : '' }}">{{ App\Services\Reporting::show($v, $t) }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($result['totals'])
                        <tfoot>
                            <tr>
                                @foreach ($config['columns'] as $key)
                                    @if ($loop->first)<th scope="row">Total</th>
                                    @else<td data-label="{{ $cols[$key][0] }}" class="{{ in_array($cols[$key][1], $numeric, true) ? 'r' : '' }}">{{ array_key_exists($key, $result['totals']) ? App\Services\Reporting::show($result['totals'][$key], $cols[$key][1]) : '' }}</td>@endif
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            @endif
        </div>
        @if (count($result['rows']) > count($shownRows))
            <p class="pg-help">Showing the first {{ count($shownRows) }} of {{ count($result['rows']) }} rows. The downloads carry them all.</p>
        @endif
    </section>
</div>
