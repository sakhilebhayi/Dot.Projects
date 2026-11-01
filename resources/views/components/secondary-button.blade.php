<button {{ $attributes->merge(['type' => 'button', 'class' => 'pg-btn pg-btn--secondary']) }}>
    {{ $slot }}
</button>
