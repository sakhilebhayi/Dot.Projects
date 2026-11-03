@php
    $canChange = $project->canBeChangedBy(auth()->user());
    // The chosen day, or today while today's month is showing, is listed under the month.
    $selected = $day !== '' ? Illuminate\Support\Carbon::parse($day) : ($first->isSameMonth(now()) ? Illuminate\Support\Carbon::today() : null);
@endphp
<div>
    <section class="pg-card pg-cal" aria-labelledby="cal-h">
        <div class="pg-cal-head">
            <div class="pg-cal-nav">
                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm pg-btn--icon" wire:click="go(-1)" aria-label="Previous month"><span class="material-symbols-rounded" aria-hidden="true">chevron_left</span></button>
                <h2 id="cal-h">{{ $first->format('F Y') }}</h2>
                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm pg-btn--icon" wire:click="go(1)" aria-label="Next month"><span class="material-symbols-rounded" aria-hidden="true">chevron_right</span></button>
                <button type="button" class="pg-btn pg-btn--secondary pg-btn--sm" wire:click="go(0)">Today</button>
            </div>
            <div class="pg-cal-tools">
                <label for="cal-show" class="pg-sr">Show</label>
                <select id="cal-show" wire:model.live="show" class="pg-select pg-select--sm">
                    <option value="all">Everything</option>
                    @foreach (App\Services\ProjectCalendar::KINDS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    <option value="mine">Only mine</option>
                </select>
            </div>
        </div>
        <p class="pg-cal-finding">
            {{ $count === 0 ? 'Nothing dated in '.$first->format('F').'.' : $count.' dated '.Str::plural('item', $count).' in '.$first->format('F').'.' }}
            Blue is work on the critical path; red is past its date.@if ($canChange) Drag an open task to another day, or focus it and use the arrow keys, to move it with its length kept.@endif
        </p>
        @error('calendar')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
        @error('schedule')<p class="pg-error" role="alert">{{ $message }}</p>@enderror

        @include('partials.programme.month-grid', ['showProject' => false])
    </section>

    @include('partials.programme.month-day', ['showProject' => false])
</div>
