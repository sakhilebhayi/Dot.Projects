<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectPeople;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The project's people and who can see it, on the settings page: the owner, members with their
 * roles, adding and removing people, and switching between open to the business and private.
 */
class PeoplePanel extends Component
{
    public Project $project;

    public string $visibility = 'team';

    public string $newPerson = '';

    public string $newRole = 'member';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
        $this->visibility = $project->visibility;
    }

    public function setVisibility(ProjectPeople $people): void
    {
        $this->authorize('changeVisibility', $this->project);
        $this->resetErrorBag();
        try {
            $people->setVisibility($this->project, $this->visibility);
        } catch (ValidationException $e) {
            $this->visibility = $this->project->visibility;
            throw $e;
        }
        $this->project->refresh();
    }

    public function add(ProjectPeople $people): void
    {
        $this->authorize('manageMembers', $this->project);
        $this->resetErrorBag();
        $people->add($this->project, auth()->user(), $this->newPerson === '' ? 0 : (int) $this->newPerson, $this->newRole);
        $this->reset('newPerson');
    }

    public function setRole(int $userId, string $role, ProjectPeople $people): void
    {
        $this->authorize('manageMembers', $this->project);
        $this->resetErrorBag();
        $people->setRole($this->project, $userId, $role);
    }

    public function remove(int $userId, ProjectPeople $people): void
    {
        $this->authorize('manageMembers', $this->project);
        $this->resetErrorBag();
        $people->remove($this->project, $userId);
    }

    public function render(): View
    {
        $roles = DB::table('project_members')->where('project_id', $this->project->id)->pluck('role', 'user_id');
        $team = $this->project->team->allUsers()->sortBy('name')->values();

        return view('livewire.projects.people-panel', [
            'members' => $team->filter(fn (User $u) => $roles->has($u->id))->map(fn (User $u) => ['user' => $u, 'role' => (string) $roles->get($u->id)])->values(),
            'candidates' => $team->reject(fn (User $u) => $roles->has($u->id) || $u->id === $this->project->owner_id)->values(),
            'businessOwner' => $this->project->team->owner,
            'canManage' => auth()->user()->can('manageMembers', $this->project),
            'canChangeVisibility' => auth()->user()->can('changeVisibility', $this->project),
        ]);
    }
}
