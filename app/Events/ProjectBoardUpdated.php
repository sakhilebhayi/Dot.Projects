<?php

namespace App\Events;

use App\Models\Project;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to everyone with a project's board open whenever a task moves,
 * gets (re)assigned, or its dependencies change -- so a second viewer sees
 * the update live instead of only on their next reload. Carries no task
 * payload of its own; listeners just re-fetch board state (ProjectBoard's
 * own tasksByStatus/allTasks queries are already team-scoped and cheap),
 * which keeps this event valid regardless of which action caused it.
 */
class ProjectBoardUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Project $project) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('project.'.$this->project->id)];
    }

    public function broadcastAs(): string
    {
        return 'board.updated';
    }
}
