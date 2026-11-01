<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectTask;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notification sent to a project's owner when
 * someone else comments on it. Dispatched from
 * App\Livewire\Projects\ProjectComments::addComment().
 */
class NewCommentNotification extends Notification
{
    public function __construct(public ProjectComment $comment, public Project $project, public ?ProjectTask $task = null) {}

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
            'title' => $this->task ? 'New comment on your task' : 'New comment on your project',
            'message' => $this->task
                ? "{$this->comment->user->name} commented on \"{$this->task->title}\" in \"{$this->project->name}\"."
                : "{$this->comment->user->name} commented on \"{$this->project->name}\".",
            'project_id' => $this->project->id,
            'comment_id' => $this->comment->id,
            'url' => $this->task ? route('projects.tasks.show', [$this->project, $this->task]) : route('projects.show', $this->project),
        ];
    }
}
