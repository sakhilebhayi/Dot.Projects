<div style="min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:22px;padding:40px max(16px, env(safe-area-inset-right, 0px)) 40px max(16px, env(safe-area-inset-left, 0px))">
    <div>{{ $logo }}</div>
    <div class="pg-card" style="width:100%;max-width:420px;padding:26px 28px">
        {{ $slot }}
    </div>
    <p class="pg-help" style="text-align:center;max-width:42ch">Dot.Projects is part of the InfoDot ecosystem. Most people sign in from InfoDot.</p>
</div>
