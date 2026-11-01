<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectReport;
use App\Models\User;

/** Readable by the project's team, written by members who can update an unarchived project. */
class ProjectReportPolicy
{
    public function view(User $user, ProjectReport $item): bool
    {
        return $user->belongsToTeam(Project::owningTeam($item->project_id));
    }

    public function update(User $user, ProjectReport $item): bool
    {
        return Project::writableBy($user, $item->project_id);
    }
}
