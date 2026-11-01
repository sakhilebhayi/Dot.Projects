<div>
    @php
        $pill = ['on_track' => 'pg-pill--positive', 'at_risk' => 'pg-pill--warning', 'off_track' => 'pg-pill--negative'];
        $published = $report->published();
    @endphp

    @if ($published)
        <article class="pg-card pg-report" aria-labelledby="rep-h">
            <div class="pg-report-head">
                <div>
                    <h2 id="rep-h">Status report {{ $report->ref() }}</h2>
                    <p class="pg-help" style="margin:0">{{ $project->name }}, {{ $report->period_from->format('j M') }} to {{ $report->period_to->format('j M Y') }}. Published by {{ $report->publisher?->name ?? 'someone' }} on {{ $report->published_at->format('j M Y') }}.</p>
                </div>
                <span class="pg-pill pg-pill--rating {{ $pill[$report->overall] ?? '' }}">{{ App\Models\ProjectReport::RATINGS[$report->overall] ?? '' }}</span>
            </div>
            <div class="pg-report-text" style="margin-top:14px">
                <section><h3>Summary</h3><p>{{ $report->summary }}</p></section>
                @if ($report->achievements)<section><h3>Done in this period</h3><p>{{ $report->achievements }}</p></section>@endif
                @if ($report->next_steps)<section><h3>Next</h3><p>{{ $report->next_steps }}</p></section>@endif
                @if ($report->help_needed)<section><h3>Help needed</h3><p>{{ $report->help_needed }}</p></section>@endif
            </div>
            <div style="border-top:1px solid var(--pg-border);margin-top:4px;padding-top:16px">
                <p class="pg-help" style="margin:0 0 12px">The facts as they stood on {{ isset($f['taken_on']) ? Illuminate\Support\Carbon::parse($f['taken_on'])->format('j M Y') : $report->published_at->format('j M Y') }}, kept as they were when the report was published.@if ($suggestion && $suggestion['rating'] !== $report->overall) The facts alone suggested {{ mb_strtolower(App\Models\ProjectReport::RATINGS[$suggestion['rating']]) }}.@endif</p>
                @include('livewire.governance._report-facts', ['f' => $f])
            </div>
            <div class="pg-actions pg-noprint" style="margin-top:16px"><button type="button" class="pg-btn pg-btn--secondary" x-data x-on:click="window.print()">Print or save as PDF</button></div>
        </article>
    @else
        <div class="pg-grid">
            <section class="pg-card pg-s7" aria-labelledby="draft-h">
                <div class="pg-card-head"><h2 id="draft-h">Draft {{ $report->ref() }}</h2><small>Not sent; nobody is told until it is published</small></div>
                <form wire:submit="save" class="pg-form">
                    <fieldset @disabled(! $canEdit) style="border:0;margin:0;padding:0;display:grid;gap:14px;min-width:0">
                        <div class="pg-form-row">
                            <div class="pg-field">
                                <label for="rp-from" class="pg-label">Period from</label>
                                <input id="rp-from" type="date" wire:model.live.debounce.500ms="from" class="pg-input">
                                @error('period_from')<p class="pg-error">{{ $message }}</p>@enderror
                            </div>
                            <div class="pg-field">
                                <label for="rp-to" class="pg-label">To</label>
                                <input id="rp-to" type="date" wire:model.live.debounce.500ms="to" class="pg-input">
                                @error('period_to')<p class="pg-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <fieldset class="pg-fieldset">
                            <legend>Overall</legend>
                            <div style="display:flex;gap:16px;flex-wrap:wrap">
                                @foreach (App\Models\ProjectReport::RATINGS as $value => $label)
                                    <label class="pg-radio"><input type="radio" wire:model="overall" value="{{ $value }}"> {{ $label }}</label>
                                @endforeach
                            </div>
                            @if ($suggestion)
                                <p class="pg-help" style="margin:10px 0 0">The facts suggest <b>{{ mb_strtolower(App\Models\ProjectReport::RATINGS[$suggestion['rating']]) }}</b>, because:</p>
                                <ul class="pg-reasons">@foreach ($suggestion['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                                <p class="pg-help" style="margin:6px 0 0">The rating is yours; say why in the summary if you see it differently.</p>
                            @endif
                        </fieldset>
                        <div class="pg-field">
                            <label for="rp-summary" class="pg-label">Summary</label>
                            <textarea id="rp-summary" wire:model="summary" rows="4" maxlength="10000" class="pg-textarea" placeholder="Where the project stands, in two or three sentences a sponsor reads first."></textarea>
                        </div>
                        <div class="pg-field">
                            <label for="rp-done" class="pg-label">Done in this period</label>
                            <textarea id="rp-done" wire:model="achievements" rows="3" maxlength="10000" class="pg-textarea"></textarea>
                        </div>
                        <div class="pg-field">
                            <label for="rp-next" class="pg-label">Next</label>
                            <textarea id="rp-next" wire:model="nextSteps" rows="3" maxlength="10000" class="pg-textarea"></textarea>
                        </div>
                        <div class="pg-field">
                            <label for="rp-help" class="pg-label">Help needed</label>
                            <textarea id="rp-help" wire:model="helpNeeded" rows="2" maxlength="10000" class="pg-textarea" placeholder="Decisions, money or people the project needs from its sponsor."></textarea>
                        </div>
                    </fieldset>
                    @error('publish')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
                    @error('report')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
                    @if ($canEdit)
                        <div class="pg-actions" style="justify-content:space-between;align-items:center;border-top:1px solid var(--pg-rule);padding-top:14px">
                            <div>
                                @if ($confirmingDiscard)
                                    <button type="button" class="pg-btn pg-btn--danger pg-btn--sm" wire:click="discard">Discard the draft</button>
                                    <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('confirmingDiscard', false)">Keep it</button>
                                @else
                                    <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="$set('confirmingDiscard', true)">Discard</button>
                                @endif
                            </div>
                            <div class="pg-actions" style="align-items:center">
                                @if (session('saved'))<span class="pg-help" role="status" style="margin:0">{{ session('saved') }}</span>@endif
                                <button type="submit" class="pg-btn pg-btn--secondary">Save the draft</button>
                                <button type="button" class="pg-btn pg-btn--primary" wire:click="publish">Publish</button>
                            </div>
                        </div>
                    @endif
                </form>
            </section>
            <section class="pg-card pg-s5" aria-labelledby="facts-h">
                <div class="pg-card-head"><h2 id="facts-h">The facts, live</h2><small>Frozen when you publish</small></div>
                @include('livewire.governance._report-facts', ['f' => $f])
            </section>
        </div>
    @endif
</div>
