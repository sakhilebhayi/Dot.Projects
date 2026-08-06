<div class="min-h-screen flex flex-col justify-center items-center px-5 py-12 bg-[var(--ink)]">
    <div class="mb-8">
        {{ $logo }}
    </div>

    <div class="w-full sm:max-w-md px-6 py-8 sm:px-8 bg-[var(--ink-soft)] border border-[var(--line)] rounded-2xl shadow-xl">
        {{ $slot }}
    </div>
</div>
