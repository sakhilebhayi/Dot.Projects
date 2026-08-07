<?php

namespace App\Notifications;

use App\Models\Milestone;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notification sent to a project's owner and
 * members when one of its milestones is due within the next two days and
 * has not yet been completed. Dispatched by the scheduled
 * App\Console\Commands\CheckMilestonesDueSoon command.
 */
class MilestoneDueSoonNotification extends Notification
{
    public function __construct(public Milestone $milestone) {}

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
        $project = $this->milestone->project;

        return [
            'type' => 'milestone_due_soon',
            'title' => 'Milestone due soon',
            'message' => "\"{$this->milestone->title}\" on \"{$project->name}\" is due ".$this->milestone->due_date->format('M d, Y').'.',
            'project_id' => $project->id,
            'milestone_id' => $this->milestone->id,
            'url' => route('projects.show', $project),
        ];
    }
}
