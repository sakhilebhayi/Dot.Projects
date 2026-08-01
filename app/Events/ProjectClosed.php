<?php

namespace App\Events;

use App\Models\Project;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a Project's status transitions to "completed" because every one
 * of its milestones has reached "completed" status.
 *
 * Corresponds to the `delivery.project.closed` payload type documented as
 * ecosystem-integration target design in Dot.Brain's platforms/dot-projects.md
 * and in this platform's own wiki.md (§5, §6). No Knowledge Pack publisher
 * consumes this yet — dispatching it now builds up an event log a future
 * publisher can consume or backfill from, per wiki.md's Open Questions.
 */
class ProjectClosed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Project $project)
    {
    }
}
