<?php

namespace App\Services;

use App\Models\AutomationRun;
use App\Models\Project;
use App\Models\RetentionPolicy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Data retention (R-34 P12b): how long each business keeps what accumulates, chosen by its owner or an
 * admin, applied every night by `data:prune`.
 *
 *  - Activity history: the audit trail of who changed what. Kept for ever by default; can be limited to
 *    1, 2, 3, 5 or 7 years.
 *  - Read notices: removed after 30, 90, 180 (default) or 365 days. Unread notices are never removed.
 *  - Automation run history: finished runs (done, nothing to do, failed) removed after 90, 180, 365
 *    (default) or 730 days. Runs still waiting or awaiting approval are never removed.
 *
 * Projects, tasks, time, money, risks, decisions, reports and documents are the business's records and are
 * never removed by retention: they go only when a person deletes them. Calls to other Dot platforms are
 * pruned 30 days after sending (App\Services\Integrations\Outbox), for every business alike.
 */
final class DataRetention
{
    public const ACTIVITY_MONTHS = ['' => 'For ever', '84' => '7 years', '60' => '5 years', '36' => '3 years', '24' => '2 years', '12' => '1 year'];

    public const NOTICE_DAYS = ['30' => '30 days', '90' => '90 days', '180' => '180 days', '365' => '1 year'];

    public const AUTOMATION_DAYS = ['90' => '90 days', '180' => '180 days', '365' => '1 year', '730' => '2 years'];

    public const DEFAULTS = ['activity_months' => null, 'notice_days' => 180, 'automation_days' => 365];

    /** The business's policy, or the defaults (unsaved) when it has not chosen. */
    public function policy(Team $team): RetentionPolicy
    {
        return RetentionPolicy::query()->firstWhere('team_id', $team->id) ?? (new RetentionPolicy(self::DEFAULTS))->forceFill(['team_id' => $team->id]);
    }

    public function set(Team $team, User $by, ?int $activityMonths, int $noticeDays, int $automationDays): RetentionPolicy
    {
        if (! Workload::oversees($by, $team)) {
            throw ValidationException::withMessages(['retention' => 'Only the business owner and admins decide how long data is kept.']);
        }
        if (! isset(self::ACTIVITY_MONTHS[(string) $activityMonths]) || ! isset(self::NOTICE_DAYS[(string) $noticeDays]) || ! isset(self::AUTOMATION_DAYS[(string) $automationDays])) {
            throw ValidationException::withMessages(['retention' => 'Choose one of the periods offered.']);
        }
        $policy = RetentionPolicy::query()->firstOrNew(['team_id' => $team->id]);
        $policy->forceFill(['team_id' => $team->id, 'activity_months' => $activityMonths, 'notice_days' => $noticeDays, 'automation_days' => $automationDays, 'updated_by' => $by->id])->save();
        Activity::record('retention.changed', $policy, ['activity_months' => $activityMonths, 'notice_days' => $noticeDays, 'automation_days' => $automationDays]);

        return $policy;
    }

    /** @return array{activity: int, notices: int, automation_runs: int} what was removed for this business */
    public function prune(Team $team, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $policy = $this->policy($team);
        $removed = ['activity' => 0, 'notices' => 0, 'automation_runs' => 0];

        if ($policy->activity_months !== null) {
            $removed['activity'] = DB::table('activities')->where('team_id', $team->id)->where('created_at', '<', $now->copy()->subMonths($policy->activity_months))->delete();
        }

        // notifications.data is a text column (a JSON operator on it fails on PostgreSQL), so read notices
        // are matched to the business's projects in PHP, a chunk at a time (by id, so deleting
        // inside a chunk never skips the rows behind it).
        $projects = Project::withoutTeamScope(fn () => Project::query()->where('team_id', $team->id)->pluck('id'))->map(fn ($id) => (int) $id)->flip();
        DB::table('notifications')->whereNotNull('read_at')->where('read_at', '<', $now->copy()->subDays($policy->notice_days))
            ->chunkById(500, function ($rows) use ($projects, &$removed) {
                $ids = $rows->filter(fn ($r) => $projects->has((int) (json_decode((string) $r->data, true)['project_id'] ?? 0)))->pluck('id')->all();
                $removed['notices'] += $ids === [] ? 0 : DB::table('notifications')->whereIn('id', $ids)->delete();
            });

        $removed['automation_runs'] = AutomationRun::query()->where('team_id', $team->id)->whereIn('status', ['done', 'skipped', 'failed'])
            ->where('updated_at', '<', $now->copy()->subDays($policy->automation_days))->delete();

        if ($policy->exists) {
            $policy->forceFill(['last_run_at' => $now, 'last_removed' => $removed])->save();
        }

        return $removed;
    }

    /** @return array{teams: int, activity: int, notices: int, automation_runs: int} */
    public function pruneAll(?Carbon $now = null): array
    {
        $total = ['teams' => 0, 'activity' => 0, 'notices' => 0, 'automation_runs' => 0];
        foreach (Team::query()->orderBy('id')->cursor() as $team) {
            foreach ($this->prune($team, $now) as $k => $n) {
                $total[$k] += $n;
            }
            $total['teams']++;
        }

        return $total;
    }
}
