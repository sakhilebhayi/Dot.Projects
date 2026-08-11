<?php

namespace App\Events;

use App\Models\ProjectTask;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a ProjectTask's move to "done" is rejected because it still
 * has an incomplete dependency.
 *
 * Corresponds to the `delivery.dependency.blocked` payload type named as
 * ecosystem-integration target design in this platform's wiki.md (§5) --
 * previously undispatched because no dependency-tracking model existed.
 * Unlike MilestoneReached/ProjectClosed (dispatched on a real status
 * transition), this fires on the *rejected attempt* itself, since "blocked"
 * describes an attempted transition that didn't happen, not a state a task
 * settles into. No Knowledge Pack publisher consumes this yet -- dispatching
 * it now builds up a real event log a future publisher can consume from.
 */
class DependencyBlocked
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public ProjectTask $task,
        public ProjectTask $blockingTask,
    ) {}
}
