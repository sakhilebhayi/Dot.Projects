<?php

namespace App\Policies;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MilestonePolicy
{
    use HandlesAuthorization;

    public function view(User $user, Milestone $milestone): bool
    {
        return $user->belongsToTeam(Project::owningTeam($milestone->project_id));
    }

    public function update(User $user, Milestone $milestone): bool
    {
        return Project::writableBy($user, $milestone->project_id);
    }

    public function delete(User $user, Milestone $milestone): bool
    {
        return Project::writableBy($user, $milestone->project_id);
    }
}
