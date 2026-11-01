<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notice about a risk or an issue: it was given to you, or someone
 * escalated it to you as the project's owner. Sent from App\Services\Governance.
 */
class GovernanceNotification extends Notification
{
    public function __construct(
        public string $type,
        public string $title,
        public string $message,
        public int $projectId,
        public string $url,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return ['type' => $this->type, 'title' => $this->title, 'message' => $this->message, 'project_id' => $this->projectId, 'url' => $this->url];
    }
}
