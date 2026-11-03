<?php

namespace App\Models\Concerns;

use App\Services\ProjectFacts;

/**
 * A record the kept project facts are read from (R-34 P13e): saving or deleting it moves its project's change
 * counter on, so the portfolio, reports and dashboards work that project out again on their next read. Two
 * changes within the same second are caught too, which a timestamp alone would miss.
 */
trait BumpsProjectFacts
{
    public static function bootBumpsProjectFacts(): void
    {
        $bump = function ($model) {
            ProjectFacts::bump(...$model->factsProjectIds());
            if (($team = $model->factsTeamId()) !== null) {
                ProjectFacts::bumpTeam($team);
            }
        };
        static::saved($bump);
        static::deleted($bump);
    }

    /** @return list<int> the projects whose facts this record feeds */
    public function factsProjectIds(): array
    {
        $project = $this->getAttribute('project_id');

        return $project !== null ? [(int) $project] : [];
    }

    /** A business whose every project's facts this record feeds (rates, timesheet approvals), if any. */
    public function factsTeamId(): ?int
    {
        return null;
    }
}
