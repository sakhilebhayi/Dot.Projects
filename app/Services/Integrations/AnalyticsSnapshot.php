<?php

namespace App\Services\Integrations;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Team;
use App\Services\ProjectFacts;
use App\Services\StatusReports;
use Illuminate\Support\Carbon;

/**
 * The daily snapshot Dot.Projects publishes to Dot.Analytics for one business (R-34 P11c), in the keys of
 * Analytics' catalogue for `projects` (its docs/metrics-contract.md). Counted over the business's active projects
 * (not archived, not completed) from the same facts its own portfolio shows, so the two always agree. Numbers
 * only: no project names, people or text leave Dot.Projects.
 */
final class AnalyticsSnapshot
{
    public const KEYS = ['active', 'off_track', 'at_risk', 'over_budget', 'overdue_tasks', 'tasks_completed_7d', 'late_milestones', 'serious_risks'];

    /** @return array<string, int> */
    public function metrics(Team $team, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $today = $now->copy()->startOfDay();
        // Read as the business, not as a person: every active project, private ones included, counted only.
        $projects = Project::withoutTeamScope(fn () => Project::query()->where('team_id', $team->id)->whereNull('archived_at')->where('status', '!=', 'completed')->get());
        $ids = $projects->pluck('id');
        $facts = app(ProjectFacts::class);
        $facts->prime($projects);
        $reports = app(StatusReports::class);

        $out = array_fill_keys(self::KEYS, 0);
        $out['active'] = $projects->count();
        foreach ($projects as $p) {
            $f = $facts->facts($p, $today->copy()->subDays(6), $today);
            $rating = $reports->suggest($f)['rating'];
            $out['off_track'] += $rating === 'off_track' ? 1 : 0;
            $out['at_risk'] += $rating === 'at_risk' ? 1 : 0;
            $out['serious_risks'] += (int) $f['risks']['serious'];
            $money = $facts->money($p);
            $out['over_budget'] += $money !== null && $money['total']['budget'] > 0 && $money['total']['variance'] < 0 ? 1 : 0;
        }
        $out['overdue_tasks'] = ProjectTask::query()->whereIn('project_id', $ids)->where('status', '!=', 'done')->whereNotNull('due_date')->whereDate('due_date', '<', $today)->count();
        $out['tasks_completed_7d'] = ProjectTask::query()->whereIn('project_id', $ids)->where('status', 'done')->where('completed_at', '>=', $now->copy()->subDays(7))->count();
        $out['late_milestones'] = Milestone::query()->whereIn('project_id', $ids)->where('status', '!=', 'completed')->whereNotNull('due_date')->whereDate('due_date', '<', $today)->count();

        return $out;
    }

    /**
     * Queues today's snapshot for a business linked to InfoDot, once a day: the outbox key is the business and the
     * date, and taken_at is fixed in the payload, so a retry sends the very same snapshot.
     */
    public function queue(Team $team, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now();
        if (blank($team->infodot_team_uuid)) {
            return false;
        }
        $payload = [
            'company_uuid' => (string) $team->infodot_team_uuid,
            'platform' => 'projects',
            'taken_at' => $now->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'period' => 'day',
            'metrics' => $this->metrics($team, $now),
        ];

        return app(Outbox::class)->push('analytics', $team->id, 'projects.snapshot', 'snapshot:'.$team->id.':'.$now->copy()->utc()->toDateString(), $payload) !== null;
    }
}
