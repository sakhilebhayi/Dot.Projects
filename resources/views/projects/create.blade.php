<x-app-layout>
    <div style="padding:2rem 2.5rem;">

        <div style="max-width:640px;">
            <div style="margin-bottom:1.75rem;">
                <a href="{{ route('dashboard') }}" style="display:inline-flex;align-items:center;gap:0.35rem;font-size:0.75rem;color:#8d90a2;text-decoration:none;margin-bottom:1rem;"
                   onmouseover="this.style.color='#b6c4ff'" onmouseout="this.style.color='#8d90a2'">
                    <span class="material-symbols-rounded" style="font-size:16px;">arrow_back</span>
                    All Projects
                </a>
                <h1 style="font-family:'Manrope',sans-serif;font-size:1.5rem;font-weight:800;color:#dae2fd;margin:0 0 0.25rem;">New Project</h1>
                <p style="font-size:0.8rem;color:#8d90a2;margin:0;">Fill in the details below, or let AI generate a full project plan.</p>
            </div>

            <div style="background:#131b2e;border:1px solid rgba(67,70,86,0.3);border-radius:0.9rem;padding:2rem;">
                <livewire:projects.create-project />
            </div>
        </div>

    </div>
</x-app-layout>
