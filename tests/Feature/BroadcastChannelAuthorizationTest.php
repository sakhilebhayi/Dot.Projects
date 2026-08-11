<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * routes/channels.php's authorization callbacks (App.Models.User.{id} and
 * project.{projectId}) are exercised elsewhere as plain unit calls against
 * ProjectChannelAuthorizer directly (ProjectBoardRealtimeTest) -- this class
 * instead hits the real /broadcasting/auth endpoint, which is the only way
 * to prove routes/channels.php's own wiring works end-to-end, including the
 * malformed-identifier fail-closed handling that a typed `int $projectId`
 * channel closure parameter would otherwise skip entirely (throwing an
 * uncaught TypeError/500 before the closure body -- and therefore
 * ProjectChannelAuthorizer -- ever runs).
 */
class BroadcastChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml forces BROADCAST_CONNECTION=null in the test env so
        // event-broadcasting tests never attempt a real network call -- but
        // the null driver's auth() is a no-op that authorizes everything
        // unconditionally. Channel-authorization callbacks are only
        // enforced by a real Pusher-protocol broadcaster (reverb/pusher),
        // so this test class opts back into that for its own requests.
        //
        // Switching the config alone isn't enough: Broadcast::channel()
        // registers callbacks on whichever driver instance is current at
        // call time, and routes/channels.php already ran against the
        // "null" driver during app bootstrap (before this setUp() runs).
        // Re-requiring it now, after switching the default, registers the
        // same callbacks on a fresh "reverb" driver instance instead.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
        ]);
        require base_path('routes/channels.php');
    }

    private function authRequest(User $user, string $channelName): TestResponse
    {
        return $this->actingAs($user)->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channelName,
        ]);
    }

    private function project(User $owner): Project
    {
        return Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Broadcast Auth Project',
            'status' => 'active',
        ]);
    }

    // -- App.Models.User.{id}: own notification channel only --

    public function test_user_can_authorize_their_own_notification_channel(): void
    {
        $user = User::factory()->create();

        $this->authRequest($user, "private-App.Models.User.{$user->id}")
            ->assertOk();
    }

    public function test_user_cannot_authorize_another_users_notification_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->authRequest($user, "private-App.Models.User.{$other->id}")
            ->assertForbidden();
    }

    public function test_non_numeric_user_identifier_fails_closed_rather_than_erroring(): void
    {
        $user = User::factory()->create();

        $this->authRequest($user, 'private-App.Models.User.not-a-number')
            ->assertForbidden();
    }

    // -- project.{projectId}: gated on team membership (ProjectPolicy::view()) --

    public function test_a_team_member_can_authorize_their_projects_channel(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);

        $this->authRequest($owner, "private-project.{$project->id}")
            ->assertOk();
    }

    public function test_an_outsider_cannot_authorize_someone_elses_project_channel(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);

        $this->authRequest($outsider, "private-project.{$project->id}")
            ->assertForbidden();
    }

    public function test_non_numeric_project_identifier_fails_closed_rather_than_erroring(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->authRequest($user, 'private-project.not-a-number')
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_authorize_any_private_channel(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->project($owner);

        $this->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-project.{$project->id}",
        ])->assertForbidden();
    }
}
