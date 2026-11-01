<section class="pg-card" id="risk-matrix" style="margin-top:var(--pg-gap)" aria-labelledby="matrix-h">
    <div class="pg-card-head"><h2 id="matrix-h">Risk matrix</h2><small>How this project rates risks</small></div>
    <div class="pg-grid" style="gap:24px">
        <form wire:submit="save" class="pg-form pg-s7">
            <fieldset @disabled(! $canEdit) style="border:0;margin:0;padding:0;display:grid;gap:14px;min-width:0">
                <div class="pg-field" style="max-width:220px">
                    <label for="mx-size" class="pg-label">Steps</label>
                    <select id="mx-size" wire:model.live="size" class="pg-select">
                        <option value="3">3 by 3</option>
                        <option value="4">4 by 4</option>
                        <option value="5">5 by 5</option>
                    </select>
                </div>
                <div class="pg-form-row">
                    <fieldset class="pg-fieldset">
                        <legend>Probability, least likely first</legend>
                        <div style="display:grid;gap:6px">
                            @for ($k = 0; $k < $size; $k++)
                                <label class="pg-sr" for="mx-p{{ $k }}">Probability step {{ $k + 1 }}</label>
                                <input id="mx-p{{ $k }}" type="text" wire:model.blur="probability.{{ $k }}" maxlength="40" class="pg-input" required>
                            @endfor
                        </div>
                    </fieldset>
                    <fieldset class="pg-fieldset">
                        <legend>Impact, smallest first</legend>
                        <div style="display:grid;gap:6px">
                            @for ($k = 0; $k < $size; $k++)
                                <label class="pg-sr" for="mx-i{{ $k }}">Impact step {{ $k + 1 }}</label>
                                <input id="mx-i{{ $k }}" type="text" wire:model.blur="impact.{{ $k }}" maxlength="40" class="pg-input" required>
                            @endfor
                        </div>
                    </fieldset>
                </div>
                <fieldset class="pg-fieldset">
                    <legend>Bands: the lowest score of each</legend>
                    <div class="pg-form-row pg-form-row--3">
                        <div class="pg-field"><label for="mx-m" class="pg-label">Medium from</label><input id="mx-m" type="number" min="2" max="{{ $size * $size }}" wire:model.live.debounce.400ms="medium" class="pg-input"></div>
                        <div class="pg-field"><label for="mx-h" class="pg-label">High from</label><input id="mx-h" type="number" min="2" max="{{ $size * $size }}" wire:model.live.debounce.400ms="high" class="pg-input"></div>
                        <div class="pg-field"><label for="mx-c" class="pg-label">Critical from</label><input id="mx-c" type="number" min="2" max="{{ $size * $size }}" wire:model.live.debounce.400ms="critical" class="pg-input"></div>
                    </div>
                    <p class="pg-help" style="margin:8px 0 0">Score is probability step times impact step, from 1 to {{ $size * $size }}. Below medium is low.</p>
                </fieldset>
                @error('matrix')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
            </fieldset>
            @if ($canEdit)
                <div class="pg-actions" style="align-items:center">
                    <button type="submit" class="pg-btn pg-btn--primary">Save the matrix</button>
                    @if (session('matrix-saved'))<span class="pg-help" role="status" style="margin:0">{{ session('matrix-saved') }}</span>@endif
                </div>
            @endif
        </form>
        <div class="pg-s5">
            @if ($preview)
                <div class="pg-rm" style="--n:{{ $preview->size }}" role="img" aria-label="Preview of the matrix bands">
                    @for ($p = $preview->size; $p >= 1; $p--)
                        <span class="pg-rm-y">{{ $preview->probabilityLabel($p) }}</span>
                        @for ($i = 1; $i <= $preview->size; $i++)<span class="pg-rm-cell b-{{ $preview->band($p * $i) }}" style="font-size:12px;font-weight:500">{{ $p * $i }}</span>@endfor
                    @endfor
                    <span></span>
                    @for ($i = 1; $i <= $preview->size; $i++)<span class="pg-rm-x">{{ $preview->impactLabel($i) }}</span>@endfor
                </div>
                <div class="pg-key" style="margin-top:10px">@foreach (App\Services\RiskMatrix::BANDS as $k => $label)<span><i class="pg-rm-swatch b-{{ $k }}"></i>{{ $label }}</span>@endforeach</div>
            @else
                <p class="pg-empty">The preview returns once each band starts above the one before it.</p>
            @endif
        </div>
    </div>
</section>
