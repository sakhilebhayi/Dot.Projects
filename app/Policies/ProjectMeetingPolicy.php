<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectMeeting;
use App\Models\User;

/** Like a task: readable by the project's team, changed by members who can update an unarchived project. */
class ProjectMeetingPolicy
{
    public function view(User $user, ProjectMeeting $item): bool
    {
        return $user->belongsToTeam(Project::owningTeam($item->project_id));
    }

    public function update(User $user, ProjectMeeting $item): bool
    {
        return Project::writableBy($user, $item->project_id);
    }
}
