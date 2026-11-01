<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectRisk;
use App\Models\User;

/** Like a task: readable by the project's team, changed by members who can update an unarchived project. */
class ProjectRiskPolicy
{
    public function view(User $user, ProjectRisk $item): bool
    {
        return Project::visibleTo($user, $item->project_id);
    }

    public function update(User $user, ProjectRisk $item): bool
    {
        return Project::writableBy($user, $item->project_id);
    }
}
