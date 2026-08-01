<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Notifications\MilestoneDueSoonNotification;
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
            'team_id'  => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name'     => 'Launch Readiness',
            'status'   => 'active',
        ]);
        $milestone = Milestone::create([
            'project_id' => $project->id,
            'title'      => 'Go-live',
            'due_date'   => now()->addDay(),
            'status'     => 'in_progress',
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
            'team_id'  => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name'     => 'Launch Readiness Two',
            'status'   => 'active',
        ]);
        $milestone = Milestone::create([
            'project_id' => $project->id,
            'title'      => 'Go-live',
            'due_date'   => now()->addDay(),
            'status'     => 'in_progress',
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
            'team_id'  => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name'     => 'Notify Index Project',
            'status'   => 'active',
        ]);
        $milestone = Milestone::create([
            'project_id' => $project->id,
            'title'      => 'Ship it',
            'due_date'   => now()->addDay(),
            'status'     => 'in_progress',
        ]);

        $owner->notify(new MilestoneDueSoonNotification($milestone));

        $this->actingAs($owner)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Milestone due soon');
    }
}
