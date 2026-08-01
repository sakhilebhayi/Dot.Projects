<?php

namespace App\Policies;

use App\Models\AiPlanLog;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * AiPlanLog rows contain the full prompt/response text sent to and received
 * from the AI planner for a project, which can include team-internal project
 * descriptions. No controller currently exposes this model, but the policy
 * is registered up front (Laravel's convention-based auto-discovery picks it
 * up with no further wiring) so that whichever route eventually surfaces the
 * audit trail — e.g. a "plan history" tab on a project — is scoped to the
 * owning team from the start rather than needing a security fix later.
 */
class AiPlanLogPolicy
{
    use HandlesAuthorization;

    public function view(User $user, AiPlanLog $log): bool
    {
        return $user->belongsToTeam($log->project->team);
    }
}
