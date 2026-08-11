<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Notifications\MilestoneDueSoonNotification;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_notification_bell_for_authenticated_user(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire('notification-bell');
    }

    public function test_unread_count_reflects_database_notifications(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Launch Readiness',
            'status' => 'active',
        ]);
        $milestone = Milestone::create([
            'project_id' => $project->id,
            'title' => 'Go-live',
            'due_date' => now()->addDay(),
            'status' => 'in_progress',
        ]);

        $owner->notify(new MilestoneDueSoonNotification($milestone));

        $this->assertDatabaseCount('notifications', 1);

        Livewire::actingAs($owner)
            ->test(NotificationBell::class)
            ->assertSet('open', false)
            ->call('toggle')
            ->assertSet('open', true)
            ->assertSee('Milestone due soon');

        $this->assertEquals(1, $owner->fresh()->unreadNotifications()->count());
    }

    public function test_mark_all_as_read_clears_unread_count(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Launch Readiness Two',
            'status' => 'active',
        ]);
        $milestone = Milestone::create([
            'project_id' => $project->id,
            'title' => 'Go-live',
            'due_date' => now()->addDay(),
            'status' => 'in_progress',
        ]);

        $owner->notify(new MilestoneDueSoonNotification($milestone));

        Livewire::actingAs($owner)
            ->test(NotificationBell::class)
            ->call('markAllAsRead');

        $this->assertEquals(0, $owner->fresh()->unreadNotifications()->count());
    }

    public function test_notifications_index_page_lists_notifications(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Notify Index Project',
            'status' => 'active',
        ]);
        $milestone = Milestone::create([
            'project_id' => $project->id,
            'title' => 'Ship it',
            'due_date' => now()->addDay(),
            'status' => 'in_progress',
        ]);

        $owner->notify(new MilestoneDueSoonNotification($milestone));

        $this->actingAs($owner)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Milestone due soon');
    }

    /**
     * refresh() is what #[On('echo-notification:App.Models.User.{userId},
     * notification')] calls when a BroadcastNotificationCreated event
     * arrives over the socket -- it never receives the notification data
     * itself, it just needs to bust the cached computed properties so the
     * next render re-queries the database. This proves that hook actually
     * picks up a notification created after the component already mounted
     * (and cached an empty/stale result), which is exactly the live-update
     * scenario the WebSocket listener exists for.
     */
    public function test_refresh_picks_up_a_notification_created_after_mount(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Refresh Test Project',
            'status' => 'active',
        ]);
        $task = $project->tasks()->create(['title' => 'Wire the board', 'status' => 'todo']);

        $component = Livewire::actingAs($owner)->test(NotificationBell::class);
        $component->assertSet('unreadCount', 0);

        $owner->notify(new TaskAssignedNotification($task));

        $component->call('refresh')
            ->call('toggle')
            ->assertSee('Task assigned to you');

        $this->assertEquals(1, $owner->fresh()->unreadNotifications()->count());
    }
}
