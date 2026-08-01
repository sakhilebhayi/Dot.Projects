<?php

namespace App\Policies;

use App\Models\Milestone;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MilestonePolicy
{
    use HandlesAuthorization;

    public function view(User $user, Milestone $milestone): bool
    {
        return $user->belongsToTeam($milestone->project->team);
    }

    public function update(User $user, Milestone $milestone): bool
    {
        return $user->belongsToTeam($milestone->project->team);
    }

    public function delete(User $user, Milestone $milestone): bool
    {
        return $user->belongsToTeam($milestone->project->team);
    }
}
