<section class="pg-card" id="people" style="margin-top:var(--pg-gap)" aria-labelledby="people-h">
    <div class="pg-card-head"><h2 id="people-h">People and access</h2><small>{{ $project->isPrivate() ? 'Private' : 'Open to the business' }}</small></div>
    <div class="pg-grid" style="gap:24px">
        <div class="pg-s5">
            <fieldset class="pg-fieldset" @disabled(! $canChangeVisibility)>
                <legend>Who can see this project</legend>
                <div style="display:grid;gap:8px">
                    <label class="pg-radio"><input type="radio" wire:model.live="visibility" value="team"> Everyone in {{ $project->team->name }}</label>
                    <label class="pg-radio"><input type="radio" wire:model.live="visibility" value="private"> Only the people on this project</label>
                </div>
                <p class="pg-help" style="margin:8px 0 0">{{ $visibility === 'private' ? 'Its owner, the people listed here and the owner of the business ('.($businessOwner?->name ?? 'the business owner').') can see it. Nobody else finds it, in lists or by link.' : 'Everyone in the business can see it; what they can change follows their role in the business.' }}</p>
                @error('visibility')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
                @if ($canChangeVisibility && $visibility !== $project->visibility)
                    <div style="margin-top:10px"><button type="button" class="pg-btn pg-btn--primary pg-btn--sm" wire:click="setVisibility">{{ $visibility === 'private' ? 'Make it private' : 'Open it to the business' }}</button></div>
                @endif
            </fieldset>
            <dl class="pg-kv" style="margin-top:14px">
                <dt>Manager</dt><dd>Runs the project: changes its work and manages its people</dd>
                <dt>Member</dt><dd>Changes its work</dd>
                <dt>Viewer</dt><dd>Reads it, changes nothing</dd>
            </dl>
        </div>
        <div class="pg-s7">
            <div class="pg-row"><div><span class="pg-avatar" style="margin-right:6px;vertical-align:-6px" aria-hidden="true">{{ App\Services\ProjectSummary::initials($project->owner?->name ?? '?') }}</span>{{ $project->owner?->name ?? 'Owner' }}</div><span class="pg-pill">Owner</span></div>
            @foreach ($members as $m)
                <div class="pg-row" wire:key="pm-{{ $m['user']->id }}">
                    <div><span class="pg-avatar" style="margin-right:6px;vertical-align:-6px" aria-hidden="true">{{ App\Services\ProjectSummary::initials($m['user']->name) }}</span>{{ $m['user']->name }}</div>
                    @if ($canManage)
                        <div class="pg-actions" style="align-items:center">
                            <label for="pm-role-{{ $m['user']->id }}" class="pg-sr">Role of {{ $m['user']->name }}</label>
                            <select id="pm-role-{{ $m['user']->id }}" class="pg-select" style="min-height:30px;padding:3px 8px;width:auto" wire:change="setRole({{ $m['user']->id }}, $event.target.value)">
                                @foreach (App\Models\Project::ROLES as $value => $label)<option value="{{ $value }}" @selected($m['role'] === $value)>{{ $label }}</option>@endforeach
                            </select>
                            <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" wire:click="remove({{ $m['user']->id }})" aria-label="Remove {{ $m['user']->name }} from the project">Remove</button>
                        </div>
                    @else
                        <span class="pg-pill">{{ App\Models\Project::ROLES[$m['role']] ?? $m['role'] }}</span>
                    @endif
                </div>
            @endforeach
            @if ($members->isEmpty())
                <p class="pg-empty">{{ $project->isPrivate() ? 'Nobody else is on this project yet, so only its owner and the business owner can see it.' : 'Nobody is listed yet. On an open project the list says who runs it; everyone in the business can still see it.' }}</p>
            @endif
            @if ($canManage && $candidates->isNotEmpty())
                <form wire:submit="add" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                    <label for="pm-new" class="pg-sr">Person to add</label>
                    <select id="pm-new" wire:model="newPerson" class="pg-select" style="flex:1 1 200px">
                        <option value="">Add someone from the business</option>
                        @foreach ($candidates as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select>
                    <label for="pm-newrole" class="pg-sr">Their role</label>
                    <select id="pm-newrole" wire:model="newRole" class="pg-select" style="width:auto">
                        @foreach (App\Models\Project::ROLES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    <button type="submit" class="pg-btn pg-btn--secondary">Add</button>
                </form>
            @endif
            @error('member')<p class="pg-error" role="alert">{{ $message }}</p>@enderror
        </div>
    </div>
</section>
