<?php

namespace Tests\Feature;

use App\Livewire\Projects\ProjectComments;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-50: Dot.Projects honours the company's entitlement, which InfoDot decides.
 *
 * At sign-in only companies InfoDot marks `entitled: true` are admitted and synced. During a session the
 * signed-in area asks InfoDot whether the current company still holds the platform (ten minutes per
 * company) and shows a plain 403 page when it does not; any failure to ask lets the person carry on.
 * The response bodies are the shapes InfoDot's own tests assert (EcosystemRedeemController and
 * CompanyPlatformsController on InfoDot main).
 */
class CompanyEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private string $serviceKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serviceKey = Str::random(40);

        config(['ecosystem.infodot' => [
            'redeem_url' => self::REDEEM, 'platform' => 'projects', 'secret' => $this->serviceKey, 'timeout' => 2,
            'provision' => true, 'entitlement_check' => true,
        ]]);
        config(['ecosystem.platforms.infodot.url' => 'https://infodot.test']);
    }

    private static function platformsUrl(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms';
    }

    /** InfoDot's read endpoint answer for one company, with this platform's own row as given. */
    private function platformsBody(string $uuid, bool $entitled, string $status): array
    {
        return [
            'platform' => 'projects',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => [
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'forms', 'name' => 'Dot.Forms', 'status' => 'active', 'entitled' => true, 'is_core' => false],
                ['key' => 'projects', 'name' => 'Dot.Projects', 'status' => $status, 'entitled' => $entitled, 'is_core' => false] + ($entitled ? ['access_mode' => 'everyone'] : []),
                ['key' => 'infodot', 'name' => 'InfoDot', 'status' => 'included', 'entitled' => true, 'is_core' => true],
            ],
        ];
    }

    private function fakePlatforms(array $answers): void
    {
        Http::swap(new Factory);
        Http::fake(collect($answers)->mapWithKeys(fn ($answer, $uuid) => [self::platformsUrl($uuid).'*' => $answer])->all());
    }

    /** A company here, linked to InfoDot, with one project, and its signed-in owner. */
    private function ownerOf(string $uuid, string $name = 'Acme Ltd'): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        Project::create(['team_id' => $team->id, 'owner_id' => $user->id, 'name' => 'Kathu plant upgrade', 'status' => 'active']);
        $user->switchTeam($team);

        return $user->fresh();
    }

    // ---------------------------------------------------------------------------------------------------
    // At sign-in (redeem)
    // ---------------------------------------------------------------------------------------------------

    private function company(string $uuid, string $name, string $role, array $marks = [], bool $personal = false): array
    {
        return ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role, 'role_name' => ucfirst($role)] + $marks;
    }

    private function signIn(string $email, array $teams, ?string $current = null)
    {
        Http::swap(new Factory);
        Http::fake([self::REDEEM => Http::response([
            'platform' => 'projects',
            'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        auth()->shouldUse('web');
        auth()->guard('web')->logout();

        return $this->post(route('ecosystem.auth'), ['token' => Str::random(40)], ['Origin' => 'https://infodot.test']);
    }

    public function test_an_entitled_company_is_admitted_and_synced(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner', ['entitled' => true, 'access' => null])], self::ACME)
            ->assertRedirect(route('dashboard'));

        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $ann = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertAuthenticatedAs($ann);
        $this->assertSame($team->id, $ann->current_team_id);
    }

    public function test_a_company_that_is_not_entitled_is_not_created_and_cannot_become_the_current_company(): void
    {
        $this->signIn('ann@acme.test', [
            $this->company(self::ACME, 'Acme Ltd', 'owner', ['entitled' => true, 'access' => null]),
            $this->company(self::GLOBEX, 'Globex', 'owner', ['entitled' => false, 'access' => 'not_activated']),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $ann = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertNull(Team::where('infodot_team_uuid', self::GLOBEX)->first(), 'a company without the platform must not be created');
        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertNotSame(self::GLOBEX, $ann->currentTeam->infodot_team_uuid);
    }

    public function test_a_company_that_lost_the_platform_is_left_as_it_is_and_the_person_is_moved_off_it(): void
    {
        $owner = ['entitled' => true, 'access' => null];
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner', $owner), $this->company(self::GLOBEX, 'Globex', 'owner', $owner)], self::GLOBEX);
        $this->signIn('bob@acme.test', [$this->company(self::GLOBEX, 'Globex', 'editor', $owner)], self::GLOBEX);

        $globex = Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail();
        $bob = User::where('email', 'bob@acme.test')->firstOrFail();
        $this->assertSame($globex->id, $bob->current_team_id);

        // Globex loses the platform; InfoDot still lists it for both people, renamed and with a new role.
        $lost = ['entitled' => false, 'access' => 'suspended'];
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner', $owner), $this->company(self::GLOBEX, 'Globex Renamed', 'owner', $lost)], self::GLOBEX)
            ->assertRedirect(route('dashboard'));

        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $this->assertSame(self::ACME, $ann->currentTeam->infodot_team_uuid, 'the person is moved to a company that holds the platform');
        $this->assertSame('Globex', $globex->fresh()->name, 'a company without the platform is not updated');

        // Bob has no other company here: InfoDot refuses the redeem when nothing is entitled, but a
        // personal workspace that is entitled lets him in, to his personal team.
        $this->signIn('bob@acme.test', [
            $this->company((string) Str::uuid(), "Bob's Team", 'owner', ['entitled' => true, 'access' => null], personal: true),
            $this->company(self::GLOBEX, 'Globex Renamed', 'admin', $lost),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $bob = $bob->fresh();
        $this->assertTrue($bob->currentTeam->personal_team);
        $this->assertTrue($bob->belongsToTeam($globex->fresh()), 'membership is left alone: the data is untouched');
        $this->assertSame('editor', $bob->teamRole($globex->fresh())->key, 'the role is not updated either');
    }

    public function test_a_response_without_the_entitled_key_behaves_exactly_as_before(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME)->assertRedirect(route('dashboard'));

        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $ann = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertAuthenticatedAs($ann);
        $this->assertSame($team->id, $ann->current_team_id);
    }

    public function test_when_nothing_is_entitled_the_person_sees_the_plain_page_and_is_not_signed_in(): void
    {
        $response = $this->signIn('ann@acme.test', [
            $this->company((string) Str::uuid(), "Ann's Team", 'owner', ['entitled' => false, 'access' => 'no_personal_access'], personal: true),
            $this->company(self::ACME, 'Acme Ltd', 'owner', ['entitled' => false, 'access' => 'payment_required']),
        ], self::ACME);

        $response->assertStatus(403)
            ->assertSee('Dot.Projects needs a payment for Acme Ltd')
            ->assertSee('Open Platforms in InfoDot')
            ->assertSee('href="https://infodot.test/platforms/projects"', false)
            ->assertDontSee('Stack trace')
            ->assertDontSee('Work in another of your companies');

        $this->assertGuest();
        $this->assertNull(User::where('email', 'ann@acme.test')->first(), 'no account is made for a person who is not admitted');
        $this->assertNull(Team::where('infodot_team_uuid', self::ACME)->first());
    }

    // ---------------------------------------------------------------------------------------------------
    // During a session
    // ---------------------------------------------------------------------------------------------------

    public function test_a_company_that_holds_the_platform_carries_on(): void
    {
        $user = $this->ownerOf(self::ACME);
        $this->fakePlatforms([self::ACME => Http::response($this->platformsBody(self::ACME, true, 'active'))]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Kathu plant upgrade');

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), self::platformsUrl(self::ACME))
            && $request['platform'] === 'projects'
            && $request->hasHeader('X-Dot-Platform', 'projects')
            && $request->hasHeader('Authorization', 'Bearer '.$this->serviceKey));
    }

    /** @return array<string, array{string, string}> */
    public static function refusals(): array
    {
        return [
            'not switched on' => ['free_while_pricing_finalised', 'Dot.Projects is not switched on for Acme Ltd'],
            'suspended' => ['suspended', 'Dot.Projects is suspended for Acme Ltd'],
            'payment needed' => ['payment_required', 'Dot.Projects needs a payment for Acme Ltd'],
            'ended' => ['subscription_expired', 'Dot.Projects has ended for Acme Ltd'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_company_that_does_not_hold_the_platform_gets_a_403_with_no_data(string $status, string $title): void
    {
        $user = $this->ownerOf(self::ACME);
        $this->fakePlatforms([self::ACME => Http::response($this->platformsBody(self::ACME, false, $status))]);

        $this->actingAs($user)->get('/dashboard')
            ->assertStatus(403)
            ->assertSee($title)
            ->assertSee('href="https://infodot.test/platforms/projects"', false)
            ->assertDontSee('Kathu plant upgrade');

        $project = Project::firstOrFail();
        $this->get(route('projects.show', $project))->assertStatus(403)->assertDontSee('Kathu plant upgrade');
        $this->get('/reports/export.csv')->assertStatus(403)->assertDontSee('Kathu plant upgrade');
        $this->get('/user/profile')->assertStatus(403);

        // Nothing changes either: Livewire's endpoint, which carries every action, is closed too.
        $this->post('/livewire/update', ['components' => []])->assertStatus(403)->assertSee($title);

        $this->assertSame('Kathu plant upgrade', $project->fresh()->name, 'the data is untouched');
    }

    /** @return array<string, array{callable(): mixed}> */
    public static function failures(): array
    {
        return [
            'timeout or connection error' => [fn () => throw new ConnectionException('timed out')],
            '401 wrong secret' => [fn () => Http::response(['error' => 'invalid_request'], 401)],
            '403 platform switched off' => [fn () => Http::response(['error' => 'invalid_request'], 403)],
            '404 unknown company' => [fn () => Http::response(['error' => 'not_found'], 404)],
            '500' => [fn () => Http::response('Server Error', 500)],
            '503' => [fn () => Http::response('', 503)],
            'malformed body' => [fn () => Http::response('<html>not json</html>', 200)],
            'no platforms list' => [fn () => Http::response(['platform' => 'projects', 'company' => ['uuid' => self::ACME, 'personal' => false]])],
            'own row missing' => [fn () => Http::response(['platform' => 'projects', 'company' => ['uuid' => self::ACME, 'personal' => false], 'platforms' => [
                ['key' => 'forms', 'name' => 'Dot.Forms', 'status' => 'active', 'entitled' => false, 'is_core' => false],
            ]])],
            'own row without a boolean' => [fn () => Http::response(['platform' => 'projects', 'company' => ['uuid' => self::ACME, 'personal' => false], 'platforms' => [
                ['key' => 'projects', 'name' => 'Dot.Projects', 'status' => 'suspended', 'entitled' => 'no', 'is_core' => false],
            ]])],
        ];
    }

    #[DataProvider('failures')]
    public function test_any_failure_to_ask_lets_the_person_carry_on_and_is_logged_once(callable $answer): void
    {
        $user = $this->ownerOf(self::ACME);
        $asked = 0;
        $this->fakePlatforms([self::ACME => function () use (&$asked, $answer) {
            $asked++;

            return $answer();
        }]);
        Log::spy();

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Kathu plant upgrade');
        $this->get('/dashboard')->assertOk();

        $this->assertSame(1, $asked, 'the failure is kept: one call for two requests');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'entitlement'))->once();
    }

    public function test_a_missing_secret_lets_the_person_carry_on_without_calling_infodot(): void
    {
        $user = $this->ownerOf(self::ACME);
        config(['ecosystem.infodot.secret' => null]);
        $this->fakePlatforms([self::ACME => Http::response($this->platformsBody(self::ACME, false, 'suspended'))]);
        Log::spy();

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->get('/dashboard')->assertOk();

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'entitlement'))->once();
    }

    public function test_the_answer_is_kept_for_ten_minutes_and_a_failure_for_one(): void
    {
        $user = $this->ownerOf(self::ACME);
        $this->fakePlatforms([self::ACME => Http::response($this->platformsBody(self::ACME, true, 'active'))]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->get('/portfolio')->assertOk();
        Http::assertSentCount(1);

        $this->travel(9)->minutes();
        $this->get('/dashboard')->assertOk();
        Http::assertSentCount(1);

        $this->travel(2)->minutes();
        $this->get('/dashboard')->assertOk();
        Http::assertSentCount(2);

        // A failure is asked again after a minute.
        Cache::flush();
        $this->fakePlatforms([self::ACME => Http::response('', 503)]);
        $this->get('/dashboard')->assertOk();
        $this->travel(30)->seconds();
        $this->get('/dashboard')->assertOk();
        Http::assertSentCount(1);
        $this->travel(31)->seconds();
        $this->get('/dashboard')->assertOk();
        Http::assertSentCount(2);
    }

    /** A real POST to Livewire's update endpoint, the way a browser sends a component action. */
    private function postComponentAction(array $snapshot, string $body)
    {
        return $this->postJson('/livewire/update', ['components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => ['body' => $body],
            'calls' => [['path' => '', 'method' => 'addComment', 'params' => []]],
        ]]]);
    }

    public function test_a_livewire_action_that_worked_is_refused_once_the_answer_expires_and_infodot_says_no(): void
    {
        $user = $this->ownerOf(self::ACME);
        $project = Project::withoutGlobalScopes()->where('team_id', $user->currentTeam->id)->firstOrFail();
        $this->fakePlatforms([self::ACME => Http::response($this->platformsBody(self::ACME, true, 'active'))]);

        $snapshot = Livewire::actingAs($user)->test(ProjectComments::class, ['project' => $project])->snapshot;

        // While the company holds the platform the action runs.
        $this->postComponentAction($snapshot, 'Fence goes up Friday.')->assertOk()->assertJsonStructure(['components' => [['snapshot', 'effects']]]);
        $this->assertSame(1, ProjectComment::count());

        // Ten minutes later InfoDot says no: the update is refused, no component comes back, nothing is written.
        $this->travel(11)->minutes();
        $this->fakePlatforms([self::ACME => Http::response($this->platformsBody(self::ACME, false, 'suspended'))]);

        $response = $this->postComponentAction($snapshot, 'Written after the suspension.');

        $response->assertStatus(403);
        $this->assertStringNotContainsString('"components"', $response->getContent());
        $this->assertStringNotContainsString('snapshot', $response->getContent());
        $this->assertSame(1, ProjectComment::count(), 'the data is unchanged');
        $this->assertSame(0, ProjectComment::where('body', 'Written after the suspension.')->count());
    }

    public function test_the_flag_off_makes_no_call(): void
    {
        $user = $this->ownerOf(self::ACME);
        config(['ecosystem.infodot.entitlement_check' => false]);
        $this->fakePlatforms([self::ACME => Http::response($this->platformsBody(self::ACME, false, 'suspended'))]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Kathu plant upgrade');

        Http::assertNothingSent();
    }

    public function test_the_flag_is_on_by_default(): void
    {
        $this->assertTrue((require config_path('ecosystem.php'))['infodot']['entitlement_check']);
    }

    public function test_exempt_routes_make_no_call(): void
    {
        $user = $this->ownerOf(self::ACME);
        $this->fakePlatforms([self::ACME => Http::response($this->platformsBody(self::ACME, false, 'suspended'))]);

        // Signed out: public pages, sign-in, health checks.
        foreach (['/', '/login', '/cookies', '/up', '/ready', '/up/scheduler'] as $path) {
            $this->assertNotSame(403, $this->get($path)->getStatusCode(), $path);
        }

        // Signed in: public pages and health checks, the handoff receiver, the API, and signing out.
        $this->actingAs($user);

        foreach (['/', '/cookies', '/up', '/ready', '/up/scheduler'] as $path) {
            $this->assertNotSame(403, $this->get($path)->getStatusCode(), $path);
        }

        $this->get('/auth/ecosystem')->assertRedirect('/login');
        $this->getJson('/api/user')->assertOk();
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();

        Http::assertNothingSent();
    }

    public function test_a_personal_workspace_is_not_judged_here(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->fakePlatforms([]);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------------------------------------
    // Personal workspaces: individuals hold the free core only
    // ---------------------------------------------------------------------------------------------------

    private function personal(array $marks): array
    {
        return $this->company((string) Str::uuid(), "Ann's Team", 'owner', $marks, personal: true);
    }

    /** Ann signs in holding Acme (entitled) and her personal workspace as marked, then moves to her personal team. */
    private function annInHerPersonalTeam(array $personalMarks): User
    {
        $this->signIn('ann@acme.test', [
            $this->personal($personalMarks),
            $this->company(self::ACME, 'Acme Ltd', 'owner', ['entitled' => true, 'access' => null]),
        ], self::ACME)->assertRedirect(route('dashboard'));

        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $own = $ann->ownedTeams()->where('personal_team', true)->firstOrFail();
        Project::create(['team_id' => $own->id, 'owner_id' => $ann->id, 'name' => 'Garden shed', 'status' => 'active']);
        $this->fakePlatforms([self::ACME => Http::response($this->platformsBody(self::ACME, true, 'active'))]);
        $this->put(route('current-team.update'), ['team_id' => $own->id])->assertRedirect();

        return $ann->fresh();
    }

    public function test_a_personal_workspace_infodot_refused_is_not_a_way_round_the_check(): void
    {
        $ann = $this->annInHerPersonalTeam(['entitled' => false, 'access' => 'no_personal_access']);
        $this->assertTrue($ann->currentTeam->personal_team);

        $this->get('/dashboard')
            ->assertStatus(403)
            ->assertSee('Dot.Projects needs a company that has switched it on')
            ->assertSee('href="https://infodot.test/platforms/projects"', false)
            ->assertSee('Switch to Acme Ltd')
            ->assertDontSee('Garden shed');
        $this->post('/livewire/update', ['components' => []])->assertStatus(403);

        Http::assertNothingSent();
        $this->assertSame('Garden shed', Project::where('name', 'Garden shed')->firstOrFail()->name, 'nothing in the personal workspace is altered');

        // Public pages and the exemptions stay open, and the entitled company can be switched to.
        $this->get('/')->assertOk();
        $this->put(route('current-team.update'), ['team_id' => Team::where('infodot_team_uuid', self::ACME)->firstOrFail()->id])->assertRedirect();
        $this->get('/dashboard')->assertOk()->assertDontSee('Garden shed');
    }

    public function test_a_team_with_no_infodot_company_follows_the_same_remembered_answer(): void
    {
        $ann = $this->annInHerPersonalTeam(['entitled' => false, 'access' => 'no_personal_access']);
        $unlinked = Team::forceCreate(['user_id' => $ann->id, 'name' => 'Side venture', 'personal_team' => false]);
        $this->put(route('current-team.update'), ['team_id' => $unlinked->id])->assertRedirect();

        $this->get('/dashboard')->assertStatus(403)->assertSee('Dot.Projects needs a company that has switched it on')->assertDontSee('Switch to Side venture');
        Http::assertNothingSent();
    }

    public function test_a_personal_workspace_infodot_allowed_carries_on(): void
    {
        $this->annInHerPersonalTeam(['entitled' => true, 'access' => null]);

        $this->get('/dashboard')->assertOk()->assertSee('Garden shed');
        Http::assertNothingSent();
    }

    public function test_a_personal_workspace_with_nothing_remembered_carries_on(): void
    {
        // An InfoDot from before R-50: no `entitled` key anywhere.
        $this->signIn('ann@acme.test', [$this->company((string) Str::uuid(), "Ann's Team", 'owner', personal: true), $this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $this->fakePlatforms([self::ACME => Http::response($this->platformsBody(self::ACME, true, 'active'))]);
        $this->put(route('current-team.update'), ['team_id' => $ann->ownedTeams()->where('personal_team', true)->firstOrFail()->id])->assertRedirect();

        $this->get('/dashboard')->assertOk();
        $this->assertFalse(session()->has('infodot.personal_entitled'));
        Http::assertNothingSent();
    }

    public function test_a_later_sign_in_replaces_what_was_remembered(): void
    {
        $this->annInHerPersonalTeam(['entitled' => false, 'access' => 'no_personal_access']);
        $this->get('/dashboard')->assertStatus(403);

        $this->signIn('ann@acme.test', [$this->personal(['entitled' => true, 'access' => null]), $this->company(self::ACME, 'Acme Ltd', 'owner', ['entitled' => true, 'access' => null])], null)
            ->assertRedirect(route('dashboard'));
        $this->assertTrue(session('infodot.personal_entitled'));
    }

    public function test_a_second_company_that_is_entitled_can_still_be_switched_to(): void
    {
        $user = $this->ownerOf(self::ACME);
        $globex = Team::forceCreate(['user_id' => $user->id, 'name' => 'Globex', 'personal_team' => false, 'infodot_team_uuid' => self::GLOBEX]);
        Project::create(['team_id' => $globex->id, 'owner_id' => $user->id, 'name' => 'Globex warehouse fit-out', 'status' => 'active']);

        $this->fakePlatforms([
            self::ACME => Http::response($this->platformsBody(self::ACME, false, 'suspended')),
            self::GLOBEX => Http::response($this->platformsBody(self::GLOBEX, true, 'active')),
        ]);

        $this->actingAs($user)->get('/dashboard')
            ->assertStatus(403)
            ->assertSee('Work in another of your companies')
            ->assertSee('Switch to Globex')
            ->assertDontSee('Globex warehouse fit-out');

        $this->put(route('current-team.update'), ['team_id' => $globex->id])->assertRedirect();

        $this->assertSame($globex->id, $user->fresh()->current_team_id);
        $this->get('/dashboard')->assertOk()->assertSee('Globex warehouse fit-out')->assertDontSee('Kathu plant upgrade');
    }
}
