<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectDecision;
use App\Models\User;

/** Like a task: readable by the project's team, changed by members who can update an unarchived project. */
class ProjectDecisionPolicy
{
    public function view(User $user, ProjectDecision $item): bool
    {
        return Project::visibleTo($user, $item->project_id);
    }

    public function update(User $user, ProjectDecision $item): bool
    {
        return Project::writableBy($user, $item->project_id);
    }
}
