<?php

namespace App\Services;

use App\Models\Activity as ActivityLine;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the activity log. Callers pass enumerated values only (statuses, ids, dates); free text
 * such as names, descriptions, comments or reasons must never be passed in $changes.
 */
final class Activity
{
    /** @param array<string, mixed> $changes */
    public static function record(string $action, Model $subject, array $changes = [], ?Project $project = null): ActivityLine
    {
        if ($project === null && $subject instanceof Project) {
            $project = $subject;
        }
        $teamId = $project !== null ? $project->team_id : $subject->getAttribute('team_id');
        // What an automation does is the rule's doing, not the person whose action set it off.
        $rule = Automations::running();
        if ($rule !== null) {
            $changes['rule_id'] = $rule;
        }

        $line = new ActivityLine;
        $line->forceFill([
            'team_id' => $teamId,
            'project_id' => $project?->id,
            'user_id' => $rule === null ? Auth::id() : null,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'changes' => $changes === [] ? null : $changes,
        ])->save();
        Automations::queue($line);

        return $line;
    }
}
