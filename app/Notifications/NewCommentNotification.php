<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\ProjectComment;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notification sent to a project's owner when
 * someone else comments on it. Dispatched from
 * App\Livewire\Projects\ProjectComments::addComment().
 */
class NewCommentNotification extends Notification
{
    public function __construct(public ProjectComment $comment, public Project $project) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'new_comment',
            'title' => 'New comment on your project',
            'message' => "{$this->comment->user->name} commented on \"{$this->project->name}\".",
            'project_id' => $this->project->id,
            'comment_id' => $this->comment->id,
            'url' => route('projects.show', $this->project),
        ];
    }
}
