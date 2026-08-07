<div class="relative min-h-screen flex flex-col justify-center items-center px-5 py-12 overflow-hidden">
    {{-- Same hero photo as welcome.blade.php (a real To Do / Doing / Done kanban board, hands
    moving sticky-note cards, by Gabriel Carvalho), with the same dark-ink scrim. --}}
    <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1746729798021-129315426424?q=80&w=2400&auto=format&fit=crop');"></div>
    <div class="absolute inset-0" style="background: radial-gradient(ellipse 68% 62% at 50% 40%, rgba(19,20,29,0.9) 0%, rgba(19,20,29,0.68) 45%, rgba(19,20,29,0.35) 74%, rgba(19,20,29,0.12) 100%);"></div>
    <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(19,20,29,0.6) 0%, transparent 18%, transparent 74%, rgba(19,20,29,0.5) 100%);"></div>

    <div class="relative z-10 mb-8">
        {{ $logo }}
    </div>

    <div class="relative z-10 w-full sm:max-w-md px-6 py-8 sm:px-8 bg-[var(--ink-soft)] border border-[var(--line)] rounded-2xl shadow-xl">
        {{ $slot }}
    </div>
</div>
