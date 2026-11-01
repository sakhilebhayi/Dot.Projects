@props(['value'])

<label {{ $attributes->merge(['class' => 'pg-label']) }}>
    {{ $value ?? $slot }}
</label>
