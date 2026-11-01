@props(['submit'])

<div {{ $attributes->merge(['class' => 'md:grid md:grid-cols-3 md:gap-6']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <div class="mt-5 md:mt-0 md:col-span-2">
        <form wire:submit="{{ $submit }}">
            <div class="pg-card" style="{{ isset($actions) ? 'border-bottom-left-radius:0;border-bottom-right-radius:0;' : '' }}padding:20px 22px">
                <div class="grid grid-cols-6 gap-6">
                    {{ $form }}
                </div>
            </div>

            @if (isset($actions))
                <div class="flex items-center justify-end" style="gap:10px;padding:12px 22px;background:var(--pg-ground);border:1px solid var(--pg-border);border-top:0;border-radius:0 0 8px 8px">
                    {{ $actions }}
                </div>
            @endif
        </form>
    </div>
</div>
