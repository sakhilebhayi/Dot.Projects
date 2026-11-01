<button {{ $attributes->merge(['type' => 'submit', 'class' => 'pg-btn pg-btn--primary']) }}>
    {{ $slot }}
</button>
