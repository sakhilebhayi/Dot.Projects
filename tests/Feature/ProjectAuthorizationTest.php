<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * As of the HasTeamScope global scope on Project (mirroring Dot.Finance's
 * HasUserScope / Dot.Notify's HasTeamScope), cross-team access to
 * `projects.show` now 404s rather than 403ing: implicit route-model
 * binding queries through the scope too, so another team's project is
 * invisible before ProjectPolicy::view() ever runs. This is intentionally
 * a stronger posture than the old assertForbidden() behavior — fail-closed
 * at the query layer, not dependent on the controller remembering to call
 * $this->authorize(). See ProjectTeamScopeTest for direct proof the scope
 * itself is load-bearing, independent of any Policy check.
 */
class ProjectAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_view_a_project_belonging_to_their_own_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id'  => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name'     => 'Own Project',
            'status'   => 'planning',
        ]);

        $this->actingAs($owner)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Own Project');
    }

    public function test_a_user_cannot_view_a_project_belonging_to_another_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id'  => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name'     => 'Someone Elses Project',
            'status'   => 'planning',
        ]);

        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($outsider)
            ->get(route('projects.show', $project))
            ->assertNotFound();
    }

    public function test_a_user_cannot_move_a_task_on_another_teams_project_board(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id'  => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name'     => 'Protected Project',
            'status'   => 'planning',
        ]);
        $task = $project->tasks()->create([
            'title'  => 'Do the thing',
            'status' => 'backlog',
        ]);

        $outsider = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($outsider)
            ->test(\App\Livewire\Projects\ProjectBoard::class, ['project' => $project])
            ->assertForbidden();
    }
}
