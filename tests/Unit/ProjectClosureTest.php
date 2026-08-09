<?php

namespace Tests\Unit;

use App\Events\ProjectClosed;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProjectClosureTest extends TestCase
{
    use RefreshDatabase;

    private function projectWithCompletedMilestones(string $status = 'active'): Project
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Test Project',
            'status' => $status,
        ]);
        $project->milestones()->create(['title' => 'Only milestone', 'status' => 'completed']);

        return $project;
    }

    public function test_close_if_all_milestones_completed_proposes_instead_of_closing(): void
    {
        Event::fake();
        $project = $this->projectWithCompletedMilestones();

        $result = $project->closeIfAllMilestonesCompleted();

        $this->assertTrue($result);
        $this->assertSame('pending_closure_approval', $project->fresh()->status);
        Event::assertNotDispatched(ProjectClosed::class);
    }

    public function test_close_if_all_milestones_completed_is_a_noop_while_already_pending(): void
    {
        $project = $this->projectWithCompletedMilestones('pending_closure_approval');

        $result = $project->closeIfAllMilestonesCompleted();

        $this->assertFalse($result);
        $this->assertSame('pending_closure_approval', $project->fresh()->status);
    }

    public function test_confirm_closure_completes_the_project_and_dispatches_the_event(): void
    {
        Event::fake();
        $project = $this->projectWithCompletedMilestones('pending_closure_approval');
        $reviewer = User::find($project->owner_id);

        $result = $project->confirmClosure();

        $this->assertTrue($result);
        $fresh = $project->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->closure_reviewed_at);
        Event::assertDispatched(ProjectClosed::class, fn ($event) => $event->project->is($fresh));
    }

    public function test_confirm_closure_is_a_noop_unless_pending(): void
    {
        Event::fake();
        $project = $this->projectWithCompletedMilestones('active');

        $result = $project->confirmClosure();

        $this->assertFalse($result);
        $this->assertSame('active', $project->fresh()->status);
        Event::assertNotDispatched(ProjectClosed::class);
    }

    public function test_reject_closure_reverts_to_active_and_records_the_reason(): void
    {
        Event::fake();
        $project = $this->projectWithCompletedMilestones('pending_closure_approval');

        $result = $project->rejectClosure('Not ready, one more review needed.');

        $this->assertTrue($result);
        $fresh = $project->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame('Not ready, one more review needed.', $fresh->closure_rejected_reason);
        Event::assertNotDispatched(ProjectClosed::class);
    }

    public function test_a_rejected_closure_can_be_proposed_again(): void
    {
        $project = $this->projectWithCompletedMilestones('pending_closure_approval');
        $project->rejectClosure('Not yet.');
        $this->assertSame('active', $project->fresh()->status);

        // Reopen and re-complete the milestone -- simulates real board activity.
        $project->milestones()->first()->update(['status' => 'pending']);
        $project->milestones()->first()->update(['status' => 'completed']);

        $result = $project->fresh()->closeIfAllMilestonesCompleted();

        $this->assertTrue($result);
        $this->assertSame('pending_closure_approval', $project->fresh()->status);
    }
}
