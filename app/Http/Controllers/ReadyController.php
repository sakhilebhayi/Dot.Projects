<?php

namespace App\Http\Controllers;

use App\Models\IntegrationDelivery;
use App\Services\Guardian\Checks\SchedulerCheck;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Readiness for uptime monitors (R-34 P12b, the shape Dot.HR's /ready has). /up only says PHP answers; /ready says
 * Dot.Projects can do its job: the database answers with no migrations pending, the cache reads back what it wrote,
 * storage is writable, the scheduler beat recently and the outbox to other Dot apps is being worked. 200 when every
 * check passes, 503 otherwise. It names checks and ages, never a host, a credential, an error message or anyone's data.
 */
class ReadyController extends Controller
{
    /** A call due this long ago and still unsent means the sender has stopped, not that a neighbour is down. */
    public const OUTBOX_OVERDUE_SECONDS = 600;

    public function __invoke(Migrator $migrator): JsonResponse
    {
        $checks = [
            'database' => $this->attempt(function () use ($migrator) {
                DB::select('select 1');
                $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
                $pending = array_diff($files, $migrator->getRepository()->getRan());

                return ['ok' => $pending === [], 'pending_migrations' => count($pending)];
            }),
            'cache' => $this->attempt(function () {
                $key = 'projects:ready:'.Str::random(8);
                Cache::put($key, 'yes', 30);

                return ['ok' => Cache::pull($key) === 'yes'];
            }),
            'storage' => ['ok' => is_writable(storage_path('framework/cache')) && is_writable(storage_path('logs'))],
            'scheduler' => $this->scheduler(),
            'outbox' => $this->attempt(fn () => $this->outbox()),
        ];

        $ok = collect($checks)->every(fn (array $check) => $check['ok']);

        return response()
            ->json(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks, 'checked_at' => now()->toIso8601String()], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /** @return array{ok: bool, last_beat_seconds_ago: int|null} */
    private function scheduler(): array
    {
        $age = SchedulerCheck::heartbeatAgeSeconds();

        // Nothing recorded yet (a fresh deploy or a cleared cache): unknown, not failed.
        return ['ok' => $age === null || $age <= SchedulerProbeController::SCHEDULER_STALE_SECONDS, 'last_beat_seconds_ago' => $age];
    }

    /** @return array{ok: bool, overdue_seconds: int} */
    private function outbox(): array
    {
        $oldest = IntegrationDelivery::query()->where('status', 'pending')->where('next_attempt_at', '<=', Carbon::now())->min('next_attempt_at');
        $overdue = $oldest === null ? 0 : max(0, (int) Carbon::parse((string) $oldest)->diffInSeconds(Carbon::now()));

        return ['ok' => $overdue <= self::OUTBOX_OVERDUE_SECONDS, 'overdue_seconds' => $overdue];
    }

    /**
     * @param  callable(): array<string, mixed>  $check
     * @return array<string, mixed>
     */
    private function attempt(callable $check): array
    {
        try {
            return $check();
        } catch (Throwable) {
            return ['ok' => false];
        }
    }
}
