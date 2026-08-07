<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectTask;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProjectCommentPolicy
{
    use HandlesAuthorization;

    /**
     * Comments are polymorphic (on a Project or a ProjectTask), so resolve the
     * owning team through whichever `commentable` the comment is attached to.
     */
    public function view(User $user, ProjectComment $comment): bool
    {
        return $user->belongsToTeam($this->team($comment));
    }

    public function update(User $user, ProjectComment $comment): bool
    {
        return $comment->user_id === $user->id || $user->belongsToTeam($this->team($comment));
    }

    public function delete(User $user, ProjectComment $comment): bool
    {
        return $comment->user_id === $user->id || $user->belongsToTeam($this->team($comment));
    }

    private function team(ProjectComment $comment): ?Team
    {
        $commentable = $comment->commentable;

        return match (true) {
            $commentable instanceof Project => $commentable->team,
            $commentable instanceof ProjectTask => $commentable->project->team,
            default => null,
        };
    }
}
