<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectIssue;
use App\Models\User;

/** Like a task: readable by the project's team, changed by members who can update an unarchived project. */
class ProjectIssuePolicy
{
    public function view(User $user, ProjectIssue $item): bool
    {
        return $user->belongsToTeam(Project::owningTeam($item->project_id));
    }

    public function update(User $user, ProjectIssue $item): bool
    {
        return Project::writableBy($user, $item->project_id);
    }
}
