<button {{ $attributes->merge(['type' => 'button', 'class' => 'pg-btn pg-btn--danger']) }}>
    {{ $slot }}
</button>
