<?php

namespace App\Broadcasting;

use App\Models\Project;
use App\Models\User;

/**
 * Authorization for the 'project.{projectId}' private channel, factored out
 * of routes/channels.php so it can be unit tested directly -- the "null"
 * and "log" broadcast drivers (what tests and local dev use by default)
 * never actually invoke a channel's callback via the /broadcasting/auth
 * HTTP endpoint, so that endpoint can't be used to verify this logic.
 *
 * Mirrors ProjectPolicy::view() exactly: a project belongs to a team, so
 * team membership is the whole authorization boundary. Looked up without
 * the global team scope (not via Project::find()) so an outsider's request
 * resolves the real row to check membership against, rather than silently
 * getting null back and failing for the wrong reason.
 */
class ProjectChannelAuthorizer
{
    public static function authorize(User $user, int $projectId): bool
    {
        $project = Project::withoutGlobalScopes()->find($projectId);

        if (! $project) {
            return false;
        }

        return $user->belongsToTeam($project->team);
    }
}
