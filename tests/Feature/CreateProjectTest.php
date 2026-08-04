<?php

namespace Tests\Feature;

use App\Livewire\Projects\CreateProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression test for the null-currentTeam gap: auth()->user()->currentTeam
 * is null whenever a user has no personal team and no current_team_id (e.g.
 * removed from their last team, or a data anomaly from ecosystem-provisioned
 * accounts that bypassed CreateNewUser's personal-team creation). save() and
 * generatePlan() are wire:click-reachable action methods on an already
 * rendered page, so per ecosystem convention they abort(403) rather than
 * redirect mid-action.
 */
class CreateProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_project_with_no_active_team_aborts_with_403(): void
    {
        $user = User::factory()->create(['current_team_id' => null]);

        Livewire::actingAs($user)
            ->test(CreateProject::class)
            ->set('name', 'Untenanted Project')
            ->call('save')
            ->assertStatus(403);
    }

    public function test_generating_a_plan_with_no_active_team_aborts_with_403(): void
    {
        $user = User::factory()->create(['current_team_id' => null]);

        Livewire::actingAs($user)
            ->test(CreateProject::class)
            ->set('name', 'Untenanted Project')
            ->call('generatePlan')
            ->assertStatus(403);
    }
}
