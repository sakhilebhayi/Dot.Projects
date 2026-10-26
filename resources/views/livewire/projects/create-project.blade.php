<div>
    <style>
        .dp-label { display:block;font-size:0.72rem;font-weight:700;color:#8d90a2;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.45rem; }
        .dp-input { width:100%;background:#0b1326;border:1px solid rgba(67,70,86,0.5);border-radius:0.5rem;padding:0.65rem 0.85rem;font-size:0.85rem;color:#dae2fd;font-family:'Inter',sans-serif;outline:none;transition:border-color 0.2s; }
        .dp-input:focus { border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,0.15); }
        .dp-input::placeholder { color:#3d4566; }
        .dp-input option { background:#131b2e;color:#dae2fd; }
    </style>

    <form wire:submit="save" style="display:flex;flex-direction:column;gap:1.25rem;">

        <div>
            <label for="livewire-projects-create-project-name" class="dp-label">Project Name</label>
            <input id="livewire-projects-create-project-name" wire:model="name" type="text" placeholder="e.g. Website Redesign" class="dp-input">
            @error('name')<p style="margin-top:0.35rem;font-size:0.72rem;color:#f87171;">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="livewire-projects-create-project-description" class="dp-label">Description</label>
            <textarea id="livewire-projects-create-project-description" wire:model="description" rows="3" placeholder="What is this project about? The more detail, the better the AI plan." class="dp-input" style="resize:vertical;min-height:80px;"></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label for="livewire-projects-create-project-status" class="dp-label">Status</label>
                <select id="livewire-projects-create-project-status" wire:model="status" class="dp-input">
                    <option value="planning">Planning</option>
                    <option value="active">Active</option>
                    <option value="on_hold">On Hold</option>
                    <option value="completed">Completed</option>
                </select>
            </div>
            <div>
                <label for="livewire-projects-create-project-startdate" class="dp-label">Start Date</label>
                <input id="livewire-projects-create-project-startdate" wire:model="startDate" type="date" class="dp-input">
            </div>
        </div>

        <div>
            <label for="livewire-projects-create-project-duedate" class="dp-label">Due Date</label>
            <input id="livewire-projects-create-project-duedate" wire:model="dueDate" type="date" class="dp-input">
        </div>

        @if($aiError)
            <div style="border-radius:0.5rem;background:rgba(234,179,8,0.08);border:1px solid rgba(234,179,8,0.25);padding:0.75rem 1rem;font-size:0.78rem;color:#fbbf24;">
                {{ $aiError }}
            </div>
        @endif

        <div style="display:flex;gap:0.75rem;padding-top:0.5rem;">
            <button type="submit"
                    style="flex:1;display:flex;align-items:center;justify-content:center;gap:0.4rem;border-radius:9999px;border:1px solid rgba(124,58,237,0.4);background:transparent;padding:0.7rem 1rem;font-family:'Manrope',sans-serif;font-size:0.78rem;font-weight:700;color:#b6c4ff;cursor:pointer;transition:background 0.2s;"
                    onmouseover="this.style.background='rgba(124,58,237,0.1)'" onmouseout="this.style.background='transparent'">
                <span class="material-symbols-outlined" style="font-size:16px;">save</span>
                Create Project
            </button>
            <button type="button" wire:click="generatePlan" wire:loading.attr="disabled"
                    style="flex:1;display:flex;align-items:center;justify-content:center;gap:0.4rem;border-radius:9999px;background:linear-gradient(135deg,#7c3aed,#5b21b6);padding:0.7rem 1rem;font-family:'Manrope',sans-serif;font-size:0.78rem;font-weight:700;color:#f7f5ff;cursor:pointer;border:none;box-shadow:0 8px 20px rgba(124,58,237,0.3);transition:opacity 0.2s;"
                    onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                <span wire:loading.remove wire:target="generatePlan" style="display:flex;align-items:center;gap:0.4rem;">
                    <span class="material-symbols-outlined" style="font-size:16px;">auto_awesome</span>
                    AI Generate Plan
                </span>
                <span wire:loading wire:target="generatePlan">Generating...</span>
            </button>
        </div>
    </form>
</div>
