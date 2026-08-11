<?php

use App\Broadcasting\ProjectChannelAuthorizer;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return is_string($id) && ctype_digit($id) && (int) $user->id === (int) $id;
});

/*
| A project's board carries live task-move/assign/dependency updates to
| everyone with it open -- authorization mirrors ProjectPolicy::view()
| (team membership on the project). See ProjectChannelAuthorizer for the
| actual check and why it's factored out into its own class.
|
| $projectId must NOT be type-hinted `int` here: Reverb passes the raw
| channel-name segment as a string, and a non-numeric segment (e.g.
| "private-project.not-a-number") against a typed `int` parameter throws
| an uncaught TypeError -- a 500, not a 403 -- before this closure's body
| ever runs. Validating and casting explicitly fails closed instead.
*/
Broadcast::channel('project.{projectId}', function ($user, $projectId) {
    if (! is_string($projectId) || ! ctype_digit($projectId)) {
        return false;
    }

    return ProjectChannelAuthorizer::authorize($user, (int) $projectId);
});
