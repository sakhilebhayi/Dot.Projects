<?php

namespace Tests\Feature;

use App\Http\Controllers\SchedulerProbeController;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\IntegrationDelivery;
use App\Models\User;
use App\Services\Guardian\Checks\AutomationCheck;
use App\Services\Guardian\Checks\OutboxCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** R-34 P12b: /ready for uptime monitors, and the Guardian's outbox and automation checks. */
class ReadyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-05 09:00:00');
        Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->toIso8601String());
    }

    private function delivery(string $status, ?string $due, ?string $updated = null): void
    {
        IntegrationDelivery::query()->forceCreate(['integration' => 'notify', 'event' => 'projects.x', 'key' => str()->random(10), 'payload' => ['a' => 1],
            'status' => $status, 'next_attempt_at' => $due, 'updated_at' => $updated ?? now(), 'created_at' => now()]);
    }

    public function test_ready_answers_without_signing_in_and_names_no_data(): void
    {
        $r = $this->get('/ready')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('ok', $r->json('status'));
        $this->assertSame(['database', 'cache', 'storage', 'scheduler', 'outbox'], array_keys($r->json('checks')));
        $this->assertSame(0, $r->json('checks.database.pending_migrations'));
    }

    public function test_a_stale_scheduler_fails_readiness(): void
    {
        Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->subMinutes(10)->toIso8601String());
        $this->get('/ready')->assertStatus(503)->assertJsonPath('checks.scheduler.ok', false)->assertJsonPath('status', 'fail');
    }

    public function test_calls_waiting_for_a_retry_are_not_a_failure_but_a_stopped_sender_is(): void
    {
        $this->delivery('pending', now()->addHours(4)->toDateTimeString());   // backing off: fine
        $this->get('/ready')->assertOk()->assertJsonPath('checks.outbox.overdue_seconds', 0);
        $this->assertSame('healthy', app(OutboxCheck::class)->run()->status());

        $this->delivery('pending', now()->subMinutes(20)->toDateTimeString()); // due 20 minutes ago, never sent
        $this->get('/ready')->assertStatus(503)->assertJsonPath('checks.outbox.ok', false);
        $this->assertSame('critical', app(OutboxCheck::class)->run()->status());
    }

    public function test_the_guardian_warns_on_given_up_calls_and_failed_runs(): void
    {
        $this->delivery('given_up', null, now()->subHours(2)->toDateTimeString());
        $outbox = app(OutboxCheck::class)->run();
        $this->assertSame('warning', $outbox->status());
        $this->assertSame(1, $outbox->toArray()['metrics']['given_up_24h']);

        $this->assertSame('healthy', app(AutomationCheck::class)->run()->status());
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $rule = AutomationRule::query()->forceCreate(['team_id' => $team->id, 'name' => 'R', 'trigger' => 'task.created', 'params' => [], 'conditions' => [], 'actions' => []]);
        AutomationRun::query()->forceCreate(['rule_id' => $rule->id, 'team_id' => $team->id, 'key' => 'k1', 'status' => 'failed', 'steps' => []]);
        $runs = app(AutomationCheck::class)->run();
        $this->assertSame('warning', $runs->status());
        $this->assertSame(['runs_24h' => 1, 'failed_24h' => 1], $runs->toArray()['metrics']);
    }
}
