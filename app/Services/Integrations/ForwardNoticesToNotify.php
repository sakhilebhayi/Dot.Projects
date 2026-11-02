<?php

namespace App\Services\Integrations;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

/**
 * Every in-app notice Dot.Projects writes is also offered to Dot.Notify as an email event, the
 * way Dot.HR does it: listening to "a database notice was written" covers every place that tells
 * someone something. What goes out is the minimum: the business, the project, what kind of thing
 * happened (the notice's title) and a link back. The notice's own text, which can name people
 * and records, stays here. Whether an email is sent is the business's choice in Notify, event by
 * event. Nothing is sent when Notify is not set up, the business is not linked to InfoDot or the
 * person has no email; and nothing here can affect the notice itself.
 */
final class ForwardNoticesToNotify
{
    public function __construct(private NotifyClient $notify, private Outbox $outbox) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User || ! $this->notify->enabled()) {
            return;
        }
        try {
            $this->forward($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function forward(NotificationSent $event): void
    {
        /** @var User $recipient */
        $recipient = $event->notifiable;
        $id = (string) $event->notification->id;
        $notice = method_exists($event->notification, 'toDatabase') ? $event->notification->toDatabase($recipient) : [];
        $project = isset($notice['project_id']) ? Project::withoutTeamScope(fn () => Project::query()->find($notice['project_id'])) : null;
        $team = $project ? Team::query()->find($project->team_id) : null;
        if ($id === '' || $project === null || $team === null || ! filled($team->infodot_team_uuid) || ! filled($recipient->email)) {
            return;
        }
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($notice['type'] ?? 'update'))) ?: 'update';
        $name = 'projects.'.$type;
        $this->outbox->push('notify', $team->id, $name, $name.':'.$id.':'.sha1(strtolower(trim($recipient->email))), [
            'platform' => (string) config('services.notify.platform', 'projects'),
            'company_uuid' => (string) $team->infodot_team_uuid,
            'event' => $name,
            'recipient' => ['email' => $recipient->email],
            'data' => [
                'business_name' => (string) $team->name,
                'project_name' => (string) $project->name,
                'title' => (string) ($notice['title'] ?? 'Update'),
                'url' => preg_replace('#^http://#i', 'https://', (string) ($notice['url'] ?? route('projects.show', $project))) ?? '',
            ],
            'idempotency_key' => $name.':'.$id,
        ]);
    }
}
