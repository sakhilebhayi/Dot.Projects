<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Integrations\AnalyticsClient;
use App\Services\Integrations\AnalyticsSnapshot;
use Illuminate\Console\Command;

/** R-34 P11c: queues each linked business's daily snapshot for Dot.Analytics; integrations:deliver sends it. */
class PublishAnalyticsSnapshots extends Command
{
    protected $signature = 'integrations:analytics-snapshots';

    protected $description = 'Queue today\'s Dot.Analytics snapshot for every business linked to InfoDot';

    public function handle(AnalyticsClient $client, AnalyticsSnapshot $snapshot): int
    {
        if (! $client->enabled()) {
            $this->info('Dot.Analytics is not set up on this server (ANALYTICS_INGEST_SECRET); nothing queued.');

            return self::SUCCESS;
        }
        $queued = 0;
        Team::query()->whereNotNull('infodot_team_uuid')->orderBy('id')->each(function (Team $team) use ($snapshot, &$queued) {
            try {
                $queued += $snapshot->queue($team) ? 1 : 0;
            } catch (\Throwable $e) {
                report($e); // one business's numbers must never stop the others'
            }
        });
        $this->info('Dot.Analytics: '.$queued.' snapshot(s) queued.');

        return self::SUCCESS;
    }
}
