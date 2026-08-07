<x-guest-layout>
    <div class="min-h-screen flex flex-col items-center px-5 py-12 bg-[var(--ink)]">
        <div class="mb-8">
            <x-authentication-card-logo />
        </div>

        <div class="w-full sm:max-w-2xl p-6 sm:p-8 bg-[var(--ink-soft)] border border-[var(--line)] rounded-2xl shadow-xl prose dark:prose-invert">
            {!! $cookies !!}
        </div>
    </div>
</x-guest-layout>
