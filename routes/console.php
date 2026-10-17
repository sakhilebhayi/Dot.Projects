<?php

use App\Console\Commands\BackupPostgresDatabase;
use App\Http\Controllers\SchedulerProbeController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dot.Projects scheduled tasks
Schedule::command('projects:check-milestones-due')->dailyAt('07:00');

// Liveness of the platform itself. /up/scheduler reports this heartbeat's
// age and answers 503 once it is older than
// SchedulerProbeController::SCHEDULER_STALE_SECONDS; the Scheduler Watchdog
// workflow turns that into a failed (emailed) run every 15 minutes, and the
// Guardian's scheduler check reads the same key.
Schedule::call(fn () => Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->toIso8601String()))
    ->everyMinute()
    ->name('scheduler-heartbeat');

// Nightly dump of the PostgreSQL database. Slot 02:45 beside the other Dot
// apps' backups (02:15 to 02:55). Fails loudly if pg_dump is missing.
Schedule::command(BackupPostgresDatabase::class)->dailyAt('02:45')->onOneServer();
