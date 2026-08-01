<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_filters_projects_by_name(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        Project::create([
            'team_id' => $user->currentTeam->id, 'owner_id' => $user->id,
            'name' => 'Website Redesign', 'status' => 'active',
        ]);
        Project::create([
            'team_id' => $user->currentTeam->id, 'owner_id' => $user->id,
            'name' => 'Mobile App Launch', 'status' => 'active',
        ]);

        $response = $this->actingAs($user)->get(route('dashboard', ['search' => 'Website']));

        $response->assertOk()
            ->assertSee('Website Redesign')
            ->assertDontSee('Mobile App Launch');
    }

    public function test_status_filter_narrows_the_project_list(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        Project::create([
            'team_id' => $user->currentTeam->id, 'owner_id' => $user->id,
            'name' => 'Active Initiative', 'status' => 'active',
        ]);
        Project::create([
            'team_id' => $user->currentTeam->id, 'owner_id' => $user->id,
            'name' => 'Completed Initiative', 'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get(route('dashboard', ['status' => 'completed']));

        $response->assertOk()
            ->assertSee('Completed Initiative')
            ->assertDontSee('Active Initiative');
    }

    public function test_dashboard_only_shows_projects_for_the_current_team(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $otherOwner = User::factory()->withPersonalTeam()->create();

        Project::create([
            'team_id' => $user->currentTeam->id, 'owner_id' => $user->id,
            'name' => 'My Team Project', 'status' => 'active',
        ]);
        Project::create([
            'team_id' => $otherOwner->currentTeam->id, 'owner_id' => $otherOwner->id,
            'name' => 'Other Team Project', 'status' => 'active',
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('My Team Project')
            ->assertDontSee('Other Team Project');
    }
}
