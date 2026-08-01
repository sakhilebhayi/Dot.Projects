<?php

namespace App\Policies;

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
        return $user->belongsToTeam($task->project->team);
    }

    public function update(User $user, ProjectTask $task): bool
    {
        return $user->belongsToTeam($task->project->team);
    }

    public function delete(User $user, ProjectTask $task): bool
    {
        return $user->belongsToTeam($task->project->team);
    }
}
