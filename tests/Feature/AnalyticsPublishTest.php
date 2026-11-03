<?php

namespace Tests\Feature;

use App\Models\IntegrationDelivery;
use App\Models\Project;
use App\Models\User;
use App\Services\CostLedger;
use App\Services\Governance;
use App\Services\Integrations\AnalyticsSnapshot;
use App\Services\Integrations\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** R-34 P11c: each linked business's daily delivery numbers, signed, to Dot.Analytics. */
class AnalyticsPublishTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '3f2b8c1e-9a4d-4e7b-8c55-1d2e3f4a5b6c';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 04:30:00');
        config(['services.analytics.url' => 'https://analytics.test/api/ingest/snapshots', 'services.analytics.secret' => 'projects-analytics-secret']);
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->owner->currentTeam->forceFill(['infodot_team_uuid' => self::UUID])->save();
        $this->actingAs($this->owner);
    }

    private function project(string $name, array $extra = []): Project
    {
        return Project::create($extra + ['team_id' => $this->owner->currentTeam->id, 'owner_id' => $this->owner->id, 'name' => $name, 'status' => 'active', 'due_date' => '2026-12-31']);
    }

    public function test_the_numbers_count_active_projects_from_the_same_facts_as_the_portfolio(): void
    {
        $kiln = $this->project('Kiln');
        $kiln->tasks()->create(['title' => 'Late', 'status' => 'todo', 'priority' => 'medium', 'due_date' => '2026-11-01']);
        $kiln->tasks()->create(['title' => 'Done', 'status' => 'done', 'priority' => 'medium', 'completed_at' => '2026-11-02 10:00:00']);
        $kiln->tasks()->create(['title' => 'Done long ago', 'status' => 'done', 'priority' => 'medium', 'completed_at' => '2026-09-01 10:00:00']);
        $kiln->milestones()->create(['title' => 'Missed', 'due_date' => '2026-10-30']);
        app(Governance::class)->addRisk($kiln, $this->owner, ['title' => 'Bricks late', 'probability' => 5, 'impact' => 5]);
        $line = app(CostLedger::class)->addLine($kiln, $this->owner, 'materials', 'Materials', 100_000, null);
        app(CostLedger::class)->addCost($kiln, $this->owner, ['budget_line_id' => $line->id, 'kind' => 'actual', 'description' => 'Bricks', 'amount_cents' => 150_000, 'dated' => '2026-11-03']);
        $this->project('Clinic');
        $this->project('Old', ['status' => 'completed']);
        $this->project('Shelved')->forceFill(['archived_at' => now()])->save();

        $m = app(AnalyticsSnapshot::class)->metrics($this->owner->currentTeam);

        $this->assertSame(AnalyticsSnapshot::KEYS, array_keys($m));
        $this->assertSame(2, $m['active'], 'completed and archived projects are not active');
        $this->assertSame(1, $m['overdue_tasks']);
        $this->assertSame(1, $m['tasks_completed_7d']);
        $this->assertSame(1, $m['late_milestones']);
        $this->assertSame(1, $m['serious_risks']);
        $this->assertSame(1, $m['over_budget']);
        $this->assertSame(1, $m['off_track'], 'a late milestone puts the kiln off track, as on the portfolio');
        $this->assertContainsOnlyInt($m);
    }

    public function test_one_snapshot_a_day_per_linked_business_and_none_when_switched_off(): void
    {
        $this->project('Kiln');
        User::factory()->withPersonalTeam()->create(); // a business never linked to InfoDot

        $this->artisan('integrations:analytics-snapshots')->expectsOutputToContain('1 snapshot(s) queued')->assertSuccessful();
        $this->artisan('integrations:analytics-snapshots')->expectsOutputToContain('0 snapshot(s) queued')->assertSuccessful();
        $row = IntegrationDelivery::query()->sole();
        $this->assertSame(['analytics', 'projects.snapshot', 'snapshot:'.$this->owner->currentTeam->id.':2026-11-04'], [$row->integration, $row->event, $row->key]);
        $this->assertSame(['company_uuid' => self::UUID, 'platform' => 'projects', 'taken_at' => '2026-11-04T04:30:00Z', 'period' => 'day'], array_diff_key($row->payload, ['metrics' => 1]));

        config(['services.analytics.secret' => '']);
        Carbon::setTestNow('2026-11-05 04:30:00');
        $this->artisan('integrations:analytics-snapshots')->expectsOutputToContain('not set up')->assertSuccessful();
        $this->assertSame(1, IntegrationDelivery::query()->count());
    }

    public function test_the_snapshot_is_sent_signed_exactly_as_analytics_checks_it(): void
    {
        $this->project('Kiln');
        $this->artisan('integrations:analytics-snapshots');
        $seen = null;
        $code = 201;
        Http::fake(function (Request $request) use (&$seen, &$code) {
            $seen = $request;

            return Http::response(['status' => 'stored'], $code);
        });

        $this->assertSame(1, app(Outbox::class)->deliver()['sent']);
        $this->assertSame('https://analytics.test/api/ingest/snapshots', $seen->url());
        $raw = $seen->body();
        $ts = $seen->header('X-InfoDot-Timestamp')[0];
        $this->assertSame('sha256='.hash_hmac('sha256', $ts.'.'.$raw, 'projects-analytics-secret'), $seen->header('X-InfoDot-Signature')[0], 'Analytics verifies HMAC over "{timestamp}.{raw body}"');
        $body = json_decode($raw, true);
        $this->assertSame(['company_uuid', 'platform', 'taken_at', 'period', 'metrics'], array_keys($body));
        $this->assertSame(AnalyticsSnapshot::KEYS, array_keys($body['metrics']));

        // A refusal is given up with what to fix; a server error is retried.
        IntegrationDelivery::query()->update(['status' => 'pending', 'next_attempt_at' => now(), 'attempts' => 0]);
        $code = 401;
        app(Outbox::class)->deliver();
        $this->assertStringContainsString('ANALYTICS_INGEST_SECRET_PROJECTS', IntegrationDelivery::query()->sole()->last_error);
        IntegrationDelivery::query()->update(['status' => 'pending', 'next_attempt_at' => now(), 'attempts' => 0]);
        $code = 503;
        $this->assertSame(1, app(Outbox::class)->deliver()['retrying']);
    }

    public function test_the_integrations_page_shows_what_would_be_sent(): void
    {
        $this->project('Kiln');
        $this->get(route('integrations.index'))->assertOk()->assertSee('Dot.Analytics')->assertSee('Active projects')->assertSee('numbers only');
    }
}
