<?php

namespace App\Notifications;

use App\Models\ProjectTask;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notification sent to a user the moment they are
 * assigned a task on a project's kanban board. Dispatched from
 * App\Livewire\Projects\ProjectBoard::assignTask().
 */
class TaskAssignedNotification extends Notification
{
    public function __construct(public ProjectTask $task) {}

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
        $project = $this->task->project;

        return [
            'type' => 'task_assigned',
            'title' => 'Task assigned to you',
            'message' => "You were assigned \"{$this->task->title}\" on \"{$project->name}\".",
            'project_id' => $project->id,
            'task_id' => $this->task->id,
            'url' => route('projects.show', $project),
        ];
    }
}
