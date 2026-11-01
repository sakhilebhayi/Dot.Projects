<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectGate;
use App\Models\User;

/** Read by the project's team, prepared by members who can update it; decided only by its named approver. */
class ProjectGatePolicy
{
    public function view(User $user, ProjectGate $item): bool
    {
        return Project::visibleTo($user, $item->project_id);
    }

    public function update(User $user, ProjectGate $item): bool
    {
        return Project::writableBy($user, $item->project_id);
    }

    public function decide(User $user, ProjectGate $gate): bool
    {
        return $gate->approver_id === $user->id && Project::writableBy($user, $gate->project_id);
    }
}
