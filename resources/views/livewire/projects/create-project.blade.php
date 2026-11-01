<div>
    <form wire:submit="save" class="pg-form">
        <div class="pg-field">
            <label for="livewire-projects-create-project-name" class="pg-label">Name</label>
            <input id="livewire-projects-create-project-name" wire:model="name" type="text" maxlength="255" placeholder="Fleet system rollout" class="pg-input" required>
            @error('name')<p class="pg-error">{{ $message }}</p>@enderror
        </div>

        <div class="pg-field">
            <label for="livewire-projects-create-project-description" class="pg-label">What it should achieve</label>
            <textarea id="livewire-projects-create-project-description" wire:model="description" rows="3" class="pg-textarea" placeholder="Put the new fleet system live at the three mine sites by February."></textarea>
            <p class="pg-help">{{ $this->aiReady() ? 'The more you say here, the better the drafted plan.' : 'Optional. You can add scope, milestones and people as you go.' }}</p>
            @error('description')<p class="pg-error">{{ $message }}</p>@enderror
        </div>

        <div class="pg-form-row--3 pg-form-row">
            <div class="pg-field">
                <label for="livewire-projects-create-project-status" class="pg-label">Status</label>
                <select id="livewire-projects-create-project-status" wire:model="status" class="pg-select">
                    <option value="planning">Planning</option>
                    <option value="active">Active</option>
                    <option value="on_hold">On hold</option>
                    <option value="completed">Completed</option>
                </select>
                @error('status')<p class="pg-error">{{ $message }}</p>@enderror
            </div>
            <div class="pg-field">
                <label for="livewire-projects-create-project-startdate" class="pg-label">Starts</label>
                <input id="livewire-projects-create-project-startdate" wire:model="startDate" type="date" class="pg-input">
                @error('startDate')<p class="pg-error">{{ $message }}</p>@enderror
            </div>
            <div class="pg-field">
                <label for="livewire-projects-create-project-duedate" class="pg-label">Due</label>
                <input id="livewire-projects-create-project-duedate" wire:model="dueDate" type="date" class="pg-input">
                @error('dueDate')<p class="pg-error">{{ $message }}</p>@enderror
            </div>
        </div>

        @if ($aiError)
            <div class="pg-notice" role="alert" style="margin:0">{{ $aiError }}</div>
        @endif

        @unless ($this->aiReady())
            <p class="pg-help" style="margin:0">AI planning is not set up here. The example plan adds three generic milestones with tasks, not tailored to this project, for you to edit or delete.</p>
        @endunless

        <div class="pg-actions" style="justify-content:flex-end;border-top:1px solid var(--pg-rule);padding-top:14px">
            <button type="button" wire:click="generatePlan" wire:loading.attr="disabled" class="pg-btn pg-btn--secondary">
                <span wire:loading.remove wire:target="generatePlan">{{ $this->aiReady() ? 'Create and draft a plan with AI' : 'Create with an example plan' }}</span>
                <span wire:loading wire:target="generatePlan">{{ $this->aiReady() ? 'Drafting the plan…' : 'Adding the example…' }}</span>
            </button>
            <button type="submit" class="pg-btn pg-btn--primary" wire:loading.attr="disabled">Create project</button>
        </div>
    </form>
</div>
