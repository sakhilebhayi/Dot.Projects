<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectChange;
use App\Models\User;

/** Read by the project's team, edited by members who can update it; decided only by its named approver. */
class ProjectChangePolicy
{
    public function view(User $user, ProjectChange $change): bool
    {
        return $user->belongsToTeam(Project::owningTeam($change->project_id));
    }

    public function update(User $user, ProjectChange $change): bool
    {
        return Project::writableBy($user, $change->project_id);
    }

    public function decide(User $user, ProjectChange $change): bool
    {
        return $change->approver_id === $user->id && Project::writableBy($user, $change->project_id);
    }
}
