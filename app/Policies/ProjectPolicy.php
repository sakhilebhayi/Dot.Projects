<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProjectPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any projects (used for the dashboard list,
     * which is already scoped to the user's current team at the query level).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the project. A project belongs to a team,
     * not directly to a user, so membership of that team is the authorization boundary.
     */
    public function view(User $user, Project $project): bool
    {
        return $user->belongsToTeam($project->team);
    }

    /**
     * Determine whether the user can create projects for their current team.
     */
    public function create(User $user): bool
    {
        $team = $user->currentTeam;

        return $team !== null && $user->hasTeamPermission($team, 'create');
    }

    /**
     * Determine whether the user can update the project (e.g. move tasks on its board,
     * add milestones, edit details).
     */
    public function update(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, 'update');
    }

    /**
     * Determine whether the user can comment on the project (adding a record, so the
     * "create" permission; a read-only viewer can read the thread but not post to it).
     */
    public function comment(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, 'create');
    }

    /**
     * Determine whether the user can delete the project. Gated on "update", not
     * "delete": this app has no delete route yet, and editors (who lack "delete")
     * have always passed this check, so it keeps that and only excludes viewers.
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, 'update');
    }

    /**
     * Determine whether the user can confirm or reject a pending project
     * closure. Narrower than update() -- any team member can edit the
     * board, but only the project's owner may finalize (or reject) an
     * automatically-proposed closure, and only while they can still write to
     * the team (a read-only viewer who still owns a project cannot).
     */
    public function closeProject(User $user, Project $project): bool
    {
        return $user->id === $project->owner_id
            && $user->hasTeamPermission($project->team, 'update');
    }
}
