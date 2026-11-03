<?php

namespace Tests\Feature;

use App\Livewire\Integrations\Status;
use App\Models\IntegrationDelivery;
use App\Models\Project;
use App\Models\User;
use App\Notifications\GovernanceNotification;
use App\Notifications\TaskAssignedNotification;
use App\Services\Integrations\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P11a: the integration outbox and Dot.Notify. Notices are offered to Notify as email
 * events with only a title, the project and a link; queued, never sent inside the request;
 * signed the way Notify verifies; retried with backoff, given up with a reason when retrying
 * cannot help; tracked on the Integrations page; private at rest.
 */
class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://notify.test/api/ingest/events';

    private const SECRET = 'test-secret-for-notify';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
        config(['services.notify.url' => self::URL, 'services.notify.secret' => self::SECRET, 'services.notify.platform' => 'projects']);
        Cache::flush();
    }

    /** @return array{boss: User, ed: User, kiln: Project} */
    private function business(bool $linked = true): array
    {
        $boss = User::factory()->withPersonalTeam()->create(['name' => 'Bongi Boss']);
        $team = $boss->currentTeam;
        if ($linked) {
            $team->forceFill(['infodot_team_uuid' => '7f3c2a10-1111-4222-8333-944455556666'])->save();
        }
        $ed = User::factory()->create(['name' => 'Ed Editor', 'email' => 'ed@example.test', 'current_team_id' => $team->id]);
        $team->users()->attach($ed, ['role' => 'editor']);
        $kiln = Project::create(['team_id' => $team->id, 'owner_id' => $boss->id, 'name' => 'Kiln reline', 'status' => 'active']);

        return ['boss' => $boss, 'ed' => $ed, 'kiln' => $kiln];
    }

    public function test_a_notice_is_queued_for_notify_with_only_its_title_project_and_link(): void
    {
        ['ed' => $ed, 'kiln' => $kiln] = $this->business();
        Http::fake();
        $ed->notify(new GovernanceNotification('risk_assigned', 'Risk given to you', 'You own R-01 "Union walks out" on "Kiln reline".', $kiln->id, 'http://localhost/projects/'.$kiln->id.'/risks/1'));

        Http::assertNothingSent();
        $d = IntegrationDelivery::query()->sole();
        $this->assertSame(['notify', 'projects.risk_assigned', 'pending'], [$d->integration, $d->event, $d->status]);
        $this->assertSame([
            'business_name' => $kiln->team->name, 'project_name' => 'Kiln reline', 'title' => 'Risk given to you', 'url' => 'https://localhost/projects/'.$kiln->id.'/risks/1',
        ], $d->payload['data']);
        $this->assertSame(['email' => 'ed@example.test'], $d->payload['recipient']);
        $this->assertStringNotContainsString('Union walks out', json_encode($d->payload));
        $raw = (string) DB::table('integration_deliveries')->value('payload');
        $this->assertStringNotContainsString('ed@example.test', $raw, 'the payload is encrypted at rest');

        $notice = new TaskAssignedNotification($kiln->tasks()->create(['title' => 'Reline', 'status' => 'todo', 'priority' => 'high']));
        $ed->notify($notice);
        $this->assertSame(2, IntegrationDelivery::query()->count());
        app(Outbox::class)->push('notify', $kiln->team_id, 'projects.task_assigned', IntegrationDelivery::query()->latest('id')->value('key'), []);
        $this->assertSame(2, IntegrationDelivery::query()->count(), 'the same notice is never queued twice');
    }

    public function test_nothing_is_queued_without_notify_an_infodot_link_or_an_email(): void
    {
        ['ed' => $ed, 'kiln' => $kiln] = $this->business(linked: false);
        $notice = fn () => new GovernanceNotification('x', 'Hello', 'Text', $kiln->id, 'https://x');
        $ed->notify($notice());
        $this->assertSame(0, IntegrationDelivery::query()->count(), 'business not linked to InfoDot');

        $kiln->team->forceFill(['infodot_team_uuid' => '7f3c2a10-1111-4222-8333-944455556666'])->save();
        config(['services.notify.secret' => '']);
        $ed->notify($notice());
        $this->assertSame(0, IntegrationDelivery::query()->count(), 'Notify not set up');
        $this->assertSame(2, $ed->notifications()->count(), 'the in-app notices are unaffected');
    }

    public function test_delivery_is_signed_as_notify_verifies_and_retried_or_given_up(): void
    {
        ['ed' => $ed, 'kiln' => $kiln] = $this->business();
        $ed->notify(new GovernanceNotification('report_published', 'Status report: on track', 'Text', $kiln->id, 'https://x'));
        $seen = null;
        $code = 202;
        Http::fake(function (Request $request) use (&$seen, &$code) {
            $seen = $request;

            return Http::response($code === 202 ? ['id' => 1, 'status' => 'routed'] : 'down', $code);
        });

        $this->assertSame(['sent' => 1, 'retrying' => 0, 'given_up' => 0], app(Outbox::class)->deliver());
        // Notify's own check (App\Services\Notify\IngestSignature in Dot.Notify), rebuilt here.
        $ts = (int) $seen->header('X-InfoDot-Timestamp')[0];
        $this->assertSame('sha256='.hash_hmac('sha256', $ts.'.'.$seen->body(), self::SECRET), $seen->header('X-InfoDot-Signature')[0]);
        $this->assertSame(Carbon::now()->timestamp, $ts);
        $this->assertEquals(['platform' => 'projects', 'event' => 'projects.report_published', 'company_uuid' => '7f3c2a10-1111-4222-8333-944455556666'], array_intersect_key(json_decode($seen->body(), true), ['platform' => 1, 'event' => 1, 'company_uuid' => 1]));
        $this->assertSame('sent', IntegrationDelivery::query()->sole()->status);

        // An outage: retried after 1, 5, 15, 60 and 240 minutes, then given up.
        $ed->notify(new GovernanceNotification('change_submitted', 'A change waits for your approval', 'Text', $kiln->id, 'https://x'));
        $code = 503;
        $d = IntegrationDelivery::query()->where('status', 'pending')->sole();
        foreach ([1, 5, 15, 60, 240] as $minutes) {
            app(Outbox::class)->deliver();
            $d->refresh();
            $this->assertSame('pending', $d->status);
            $this->assertSame(Carbon::now()->addMinutes($minutes)->toDateTimeString(), $d->next_attempt_at->toDateTimeString());
            Cache::flush(); // keep the breaker out of this part
            Carbon::setTestNow($d->next_attempt_at);
        }
        app(Outbox::class)->deliver();
        $d->refresh();
        $this->assertSame(['given_up', 6, 503], [$d->status, $d->attempts, $d->last_status]);
        $this->assertStringStartsWith('Gave up after 6 attempts.', $d->last_error);
    }

    public function test_a_refusal_is_given_up_at_once_and_a_failing_notify_trips_the_breaker(): void
    {
        ['ed' => $ed, 'kiln' => $kiln] = $this->business();
        $ed->notify(new GovernanceNotification('x', 'One', 'Text', $kiln->id, 'https://x'));
        $code = 401;
        Http::fake(function () use (&$code) {
            return Http::response($code === 401 ? ['error' => 'invalid_request'] : 'down', $code);
        });
        app(Outbox::class)->deliver();
        $d = IntegrationDelivery::query()->sole();
        $this->assertSame(['given_up', 401], [$d->status, $d->last_status]);
        $this->assertStringContainsString('NOTIFY_INGEST_SECRET_PROJECTS', $d->last_error);

        foreach (range(1, 6) as $i) {
            $ed->notify(new GovernanceNotification('x', 'Notice '.$i, 'Text', $kiln->id, 'https://x'));
        }
        $code = 503;
        app(Outbox::class)->deliver();
        Http::assertSentCount(6); // the refused one above, then five before the breaker opens
        $this->assertSame(1, IntegrationDelivery::query()->where('last_error', 'Dot.Notify failed several times in a row; pausing for a minute.')->count(), 'the sixth waits for the breaker');
    }

    public function test_owners_and_admins_see_what_happened_and_can_send_again(): void
    {
        ['boss' => $boss, 'ed' => $ed, 'kiln' => $kiln] = $this->business();
        $ed->notify(new GovernanceNotification('risk_escalated', 'Risk escalated to you', 'Text', $kiln->id, 'https://x'));
        Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);
        app(Outbox::class)->deliver();

        $page = Livewire::actingAs($boss)->test(Status::class)->assertSee('Some notices not sent')->assertSee('0 sent, 0 waiting, 1 not sent')
            ->assertSee('risk escalated')->assertSee('Dot.Notify does not know this business')->assertSee('ANALYTICS_INGEST_SECRET_PROJECTS');
        $id = IntegrationDelivery::query()->sole()->id;
        $page->call('sendAgain', $id);
        $this->assertSame(['pending', 0], [IntegrationDelivery::query()->sole()->status, IntegrationDelivery::query()->sole()->attempts]);

        Livewire::actingAs($ed)->test(Status::class)->assertForbidden();
        $this->actingAs($boss)->get(route('integrations.index'))->assertOk()->assertSee('>Integrations<', false);
        $this->actingAs($ed)->get(route('dashboard'))->assertOk()->assertDontSee('>Integrations<', false);

        config(['services.notify.url' => '']);
        Livewire::actingAs($boss)->test(Status::class)->assertSee('Not set up on this server')->assertSee('NOTIFY_INGEST_SECRET_PROJECTS');
    }

    public function test_delivered_rows_are_pruned_after_thirty_days(): void
    {
        ['ed' => $ed, 'kiln' => $kiln] = $this->business();
        $ed->notify(new GovernanceNotification('x', 'Old', 'Text', $kiln->id, 'https://x'));
        Http::fake(['*' => Http::response([], 202)]);
        app(Outbox::class)->deliver();
        Carbon::setTestNow('2026-12-05 09:00:00');
        $this->artisan('integrations:deliver --prune')->expectsOutputToContain('1 delivered row(s) removed.')->assertSuccessful();
        $this->assertSame(0, IntegrationDelivery::query()->count());
        $this->artisan('integrations:deliver')->expectsOutputToContain('Integrations: 0 sent, 0 to retry, 0 not sent.')->assertSuccessful();
    }
}
