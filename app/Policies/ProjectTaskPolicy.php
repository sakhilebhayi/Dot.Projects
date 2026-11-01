<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProjectTaskPolicy
{
    use HandlesAuthorization;

    /**
     * A task's authorization boundary is the team that owns its parent project,
     * matching ProjectPolicy — a task never belongs to a user directly.
     */
    public function view(User $user, ProjectTask $task): bool
    {
        return $user->belongsToTeam(Project::owningTeam($task->project_id));
    }

    public function update(User $user, ProjectTask $task): bool
    {
        return $user->hasTeamPermission(Project::owningTeam($task->project_id), 'update');
    }

    public function delete(User $user, ProjectTask $task): bool
    {
        return $user->hasTeamPermission(Project::owningTeam($task->project_id), 'update');
    }
}
