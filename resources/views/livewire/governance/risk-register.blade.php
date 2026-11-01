<div>
    @php
        $matrix = $this->matrix;
        $live = $this->all->filter(fn ($r) => $r->isLive());
        $band = fn ($r) => $matrix->band($this->rating === 'inherent' ? $r->score() : $r->currentScore());
        $hot = $live->filter(fn ($r) => in_array($band($r), ['high', 'critical'], true))->count();
        $escalated = $live->whereNotNull('escalated_at')->count();
        $overdue = $live->filter(fn ($r) => $r->reviewOverdue())->count();
        $counts = $live->countBy(fn ($r) => $this->at($r));
        $pill = ['low' => '', 'medium' => 'pg-pill--warning', 'high' => 'pg-pill--negative', 'critical' => 'pg-pill--negative pg-pill--solid'];
        $ranges = [
            'low' => '1 to '.($matrix->bands['medium'] - 1),
            'medium' => $matrix->bands['medium'].' to '.($matrix->bands['high'] - 1),
            'high' => $matrix->bands['high'].' to '.($matrix->bands['critical'] - 1),
            'critical' => $matrix->bands['critical'].' to '.($matrix->size * $matrix->size),
        ];
        $attention = $live->map(function ($r) use ($matrix) {
            $reason = match (true) {
                $r->escalated_at !== null => 'Escalated by '.($r->escalator?->name ?? 'someone').' on '.$r->escalated_at->format('j M'),
                $r->reviewOverdue() => 'Review was due '.$r->review_date->format('j M'),
                in_array($matrix->band($r->score()), ['high', 'critical'], true) && trim((string) $r->mitigation) === '' => 'Rated '.$matrix->band($r->score()).' with no mitigation plan',
                $r->owner_id === null => 'Nobody owns it',
                default => null,
            };

            return $reason ? ['risk' => $r, 'reason' => $reason, 'neg' => $r->escalated_at !== null || $r->reviewOverdue()] : null;
        })->filter()->take(6);
        $cellLabel = function (string $cell) use ($matrix) {
            [$p, $i] = array_map('intval', explode('-', $cell));

            return $matrix->probabilityLabel($p).' and '.mb_strtolower($matrix->impactLabel($i));
        };
    @endphp

    <div class="pg-titlerow" style="margin-top:0">
        <p class="pg-finding" style="margin:0">
            @if ($this->all->isEmpty())
                No risks recorded yet. Start with what could stop this project landing on time, on budget or safely.
            @elseif ($live->isEmpty())
                No live risks. {{ $this->all->count() }} {{ Str::plural('risk', $this->all->count()) }} closed or happened.
            @else
                <b>{{ $live->count() }} live {{ Str::plural('risk', $live->count()) }}</b>{{ $hot || $escalated || $overdue ? ':' : '.' }}
                @php $parts = array_filter([$hot ? $hot.' rated high or critical' : null, $escalated ? $escalated.' escalated' : null, $overdue ? $overdue.' '.Str::plural('review', $overdue).' overdue' : null]); @endphp
                @if ($parts){{ implode(', ', $parts) }}.@endif
            @endif
        </p>
        @if ($canEdit)
            <div class="pg-actions"><a href="#add-risk" class="pg-btn pg-btn--primary" x-data x-on:click.prevent="document.getElementById('add-risk').open = true; $nextTick(() => document.getElementById('r-title').focus())">Add a risk</a></div>
        @endif
    </div>

    @if ($canEdit)
        <details id="add-risk" class="pg-card pg-add" @if ($this->all->isEmpty() || $errors->any()) open @endif style="margin-bottom:var(--pg-gap)">
            <summary class="pg-card-head" style="cursor:pointer;margin:0"><h2>Add a risk</h2><small>Rate it now; plans and reviews go on its page</small></summary>
            <form wire:submit="add" class="pg-form" style="margin-top:12px">
                <div class="pg-field">
                    <label for="r-title" class="pg-label">What could happen</label>
                    <input id="r-title" type="text" wire:model="title" maxlength="255" class="pg-input" placeholder="Customs holds the trucks past the go-live date" required>
                    @error('title')<p class="pg-error">{{ $message }}</p>@enderror
                </div>
                <div class="pg-form-row pg-form-row--3">
                    <div class="pg-field">
                        <label for="r-prob" class="pg-label">How likely</label>
                        <select id="r-prob" wire:model="probability" class="pg-select" required>
                            <option value="">Choose</option>
                            @foreach ($matrix->probability as $k => $label)<option value="{{ $k + 1 }}">{{ $label }}</option>@endforeach
                        </select>
                        @error('probability')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="r-impact" class="pg-label">Impact if it does</label>
                        <select id="r-impact" wire:model="impact" class="pg-select" required>
                            <option value="">Choose</option>
                            @foreach ($matrix->impact as $k => $label)<option value="{{ $k + 1 }}">{{ $label }}</option>@endforeach
                        </select>
                        @error('impact')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="r-cat" class="pg-label">Category</label>
                        <select id="r-cat" wire:model="category" class="pg-select">
                            @foreach (App\Services\Governance::CATEGORIES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="pg-form-row pg-form-row--3">
                    <div class="pg-field">
                        <label for="r-owner" class="pg-label">Owner</label>
                        <select id="r-owner" wire:model="owner" class="pg-select">
                            <option value="">Nobody yet</option>
                            @foreach ($people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                        </select>
                        @error('owner_id')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field">
                        <label for="r-review" class="pg-label">Next review</label>
                        <input id="r-review" type="date" wire:model="review" class="pg-input">
                        @error('review_date')<p class="pg-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="pg-field" style="align-self:end">
                        <button type="submit" class="pg-btn pg-btn--primary">Add the risk</button>
                    </div>
                </div>
            </form>
        </details>
    @endif

    <div class="pg-grid">
        <section class="pg-card pg-s5" aria-labelledby="rm-h">
            <div class="pg-card-head">
                <h2 id="rm-h">Matrix</h2>
                <span class="pg-seg" role="group" aria-label="Which rating">
                    <button type="button" wire:click="$set('rating', 'current')" aria-pressed="{{ $rating === 'current' ? 'true' : 'false' }}">Now</button>
                    <button type="button" wire:click="$set('rating', 'inherent')" aria-pressed="{{ $rating === 'inherent' ? 'true' : 'false' }}">Before mitigation</button>
                </span>
            </div>
            <div class="pg-rm" style="--n:{{ $matrix->size }}" role="group" aria-label="Live risks by probability and impact">
                <span class="pg-rm-axis pg-rm-axis--y">Probability</span>
                @for ($p = $matrix->size; $p >= 1; $p--)
                    <span class="pg-rm-y">{{ $matrix->probabilityLabel($p) }}</span>
                    @for ($i = 1; $i <= $matrix->size; $i++)
                        @php
                            $key = $p.'-'.$i;
                            $b = $matrix->band($p * $i);
                            $n = $counts->get($key, 0);
                        @endphp
                        <button type="button" class="pg-rm-cell b-{{ $b }} {{ $cell === $key ? 'is-on' : '' }}" wire:click="pick('{{ $key }}')" aria-pressed="{{ $cell === $key ? 'true' : 'false' }}"
                                aria-label="{{ $cellLabel($key) }}, score {{ $p * $i }}, {{ $b }}: {{ $n }} {{ Str::plural('risk', $n) }}" title="Score {{ $p * $i }}, {{ $b }}">{{ $n ?: '' }}</button>
                    @endfor
                @endfor
                <span></span>
                @for ($i = 1; $i <= $matrix->size; $i++)<span class="pg-rm-x">{{ $matrix->impactLabel($i) }}</span>@endfor
                <span></span><span class="pg-rm-axis" style="grid-column:2 / -1">Impact</span>
            </div>
            <div class="pg-key" style="margin-top:12px">
                @foreach (App\Services\RiskMatrix::BANDS as $k => $label)<span><i class="pg-rm-swatch b-{{ $k }}"></i>{{ $label }} {{ $ranges[$k] }}</span>@endforeach
            </div>
            <p class="pg-help" style="margin:8px 0 0">Score is probability times impact on this project's {{ $matrix->size }} by {{ $matrix->size }} matrix. @can('update', $project)<a href="{{ route('projects.settings', $project) }}#risk-matrix">Change the matrix</a>.@endcan</p>
        </section>

        <section class="pg-card pg-s7" aria-labelledby="att-h">
            <div class="pg-card-head"><h2 id="att-h">Needs attention</h2><small>{{ $attention->count() }} of {{ $live->count() }} live</small></div>
            @forelse ($attention as $a)
                <div class="pg-row" wire:key="att-{{ $a['risk']->id }}">
                    <div style="min-width:0">
                        <span class="pg-id">{{ $a['risk']->ref() }}</span>
                        <a href="{{ route('projects.risks.show', [$project, $a['risk']]) }}">{{ $a['risk']->title }}</a>
                        <div class="pg-sub {{ $a['neg'] ? 'pg-neg' : '' }}">{{ $a['reason'] }}</div>
                    </div>
                    <span class="pg-pill {{ $pill[$band($a['risk'])] }}">{{ ucfirst($band($a['risk'])) }}</span>
                </div>
            @empty
                <p class="pg-empty">{{ $live->isEmpty() ? 'Escalations, overdue reviews and serious risks with no plan will show here.' : 'Every live risk has an owner, a plan where it needs one, and a review that is not overdue.' }}</p>
            @endforelse
        </section>
    </div>

    <section class="pg-table-wrap" style="margin-top:var(--pg-gap)" aria-labelledby="reg-h">
        <div class="pg-filters" style="padding:12px 16px 4px;margin:0">
            <h2 id="reg-h" style="font-size:14px;font-weight:600;margin:0 auto 0 0">Register</h2>
            @if ($cell !== '')
                <span class="pg-chip">{{ $cellLabel($cell) }} <button type="button" wire:click="$set('cell', '')" aria-label="Show every cell" style="border:0;background:none;padding:0 0 0 4px;cursor:pointer;color:var(--pg-muted);font:inherit">×</button></span>
            @endif
            <label for="reg-show" class="pg-sr">Which risks</label>
            <select id="reg-show" wire:model.live="show" class="pg-select" style="width:auto">
                <option value="live">Live</option>
                <option value="closed">Closed or happened</option>
                <option value="all">All</option>
            </select>
        </div>
        @if ($this->risks->isEmpty())
            <p class="pg-empty" style="padding:8px 16px 16px">{{ $this->all->isEmpty() ? 'The register lists every risk with its owner, rating and next review.' : 'No risk matches.' }}</p>
        @else
            <table class="pg-table pg-table--risks">
                <thead><tr><th scope="col">Risk</th><th scope="col">Owner</th><th scope="col">Rating</th><th scope="col">After mitigation</th><th scope="col" class="r">Next review</th><th scope="col">Status</th></tr></thead>
                <tbody>
                    @foreach ($this->risks as $r)
                        @php
                            $ib = $matrix->band($r->score());
                            $rs = $r->residualScore();
                        @endphp
                        <tr wire:key="risk-{{ $r->id }}">
                            <td class="pg-name"><span class="pg-id">{{ $r->ref() }}</span> <a href="{{ route('projects.risks.show', [$project, $r]) }}" @if (! $r->isLive()) style="color:var(--pg-muted)" @endif>{{ $r->title }}</a><span class="pg-sub">{{ App\Services\Governance::CATEGORIES[$r->category] ?? 'Other' }}@if ($r->escalated_at && $r->isLive()), escalated @endif</span></td>
                            <td data-label="Owner" class="{{ $r->owner ? '' : 'pg-muted' }}">{{ $r->owner?->name ?? 'Nobody' }}</td>
                            <td data-label="Rating"><span class="pg-pill {{ $pill[$ib] }}">{{ ucfirst($ib) }} {{ $r->score() }}</span></td>
                            <td data-label="After mitigation">@if ($rs !== null)@php $rb = $matrix->band($rs); @endphp<span class="pg-pill {{ $pill[$rb] }}">{{ ucfirst($rb) }} {{ $rs }}</span>@else<span class="pg-muted">Not rated</span>@endif</td>
                            <td data-label="Next review" class="r {{ $r->reviewOverdue() ? 'pg-neg' : ($r->review_date ? '' : 'pg-muted') }}">{{ $r->review_date?->format('j M') ?? '–' }}</td>
                            <td data-label="Status">{{ App\Models\ProjectRisk::STATUSES[$r->status] ?? $r->status }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
