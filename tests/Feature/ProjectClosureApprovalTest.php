<?php

namespace Tests\Feature;

use App\Events\ProjectClosed;
use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectClosureApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function pendingProject(User $owner): Project
    {
        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Test Project',
            'status' => 'pending_closure_approval',
        ]);
        $project->milestones()->create(['title' => 'Only milestone', 'status' => 'completed']);

        return $project;
    }

    public function test_owner_can_confirm_closure(): void
    {
        Event::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->pendingProject($owner);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('confirmProjectClosure');

        $fresh = $project->fresh();
        $this->assertSame('completed', $fresh->status);
        Event::assertDispatched(ProjectClosed::class, fn ($event) => $event->project->is($fresh));
    }

    public function test_owner_can_reject_with_a_reason(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->pendingProject($owner);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('promptRejectClosure')
            ->set('closureRejectReason', 'Needs one more review.')
            ->call('confirmRejectClosure');

        $fresh = $project->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame('Needs one more review.', $fresh->closure_rejected_reason);
    }

    public function test_rejecting_without_a_reason_leaves_status_unchanged(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->pendingProject($owner);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('promptRejectClosure')
            ->set('closureRejectReason', '')
            ->call('confirmRejectClosure');

        $this->assertSame('pending_closure_approval', $project->fresh()->status);
    }

    public function test_non_owner_team_member_cannot_confirm_or_reject_despite_having_board_access(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->pendingProject($owner);
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);
        $member->forceFill(['current_team_id' => $owner->currentTeam->id])->save();

        $this->withoutExceptionHandling();
        $this->expectException(AuthorizationException::class);

        Livewire::actingAs($member)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('confirmProjectClosure');
    }
}
