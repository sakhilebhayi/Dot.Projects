<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectTask;
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
        return Project::visibleTo($user, $this->projectId($comment));
    }

    public function update(User $user, ProjectComment $comment): bool
    {
        return $this->canWrite($user, $comment);
    }

    public function delete(User $user, ProjectComment $comment): bool
    {
        return $this->canWrite($user, $comment);
    }

    /**
     * Editing or deleting a comment is a write: it needs the right to change the project, so a
     * read-only viewer cannot, even on a comment they wrote before being made a viewer.
     */
    private function canWrite(User $user, ProjectComment $comment): bool
    {
        return Project::writableBy($user, $this->projectId($comment));
    }

    private function projectId(ProjectComment $comment): ?int
    {
        $id = match ($comment->commentable_type) {
            Project::class => $comment->commentable_id,
            ProjectTask::class => ProjectTask::query()->whereKey($comment->commentable_id)->value('project_id'),
            default => null,
        };

        return $id === null ? null : (int) $id;
    }
}
