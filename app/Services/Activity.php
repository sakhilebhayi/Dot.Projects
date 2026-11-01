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
        $project ??= $subject instanceof Project ? $subject : null;
        $teamId = $project?->team_id ?? $subject->getAttribute('team_id');

        $line = new ActivityLine;
        $line->forceFill([
            'team_id' => $teamId,
            'project_id' => $project?->id,
            'user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'changes' => $changes === [] ? null : $changes,
        ])->save();

        return $line;
    }
}
