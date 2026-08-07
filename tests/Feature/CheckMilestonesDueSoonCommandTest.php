<?php

namespace Tests\Feature;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Notifications\MilestoneDueSoonNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckMilestonesDueSoonCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_notifies_the_owner_of_a_milestone_due_within_two_days(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Due Soon Project',
            'status' => 'active',
        ]);
        $milestone = Milestone::create([
            'project_id' => $project->id,
            'title' => 'Beta launch',
            'due_date' => now()->addDay(),
            'status' => 'in_progress',
        ]);

        $this->artisan('projects:check-milestones-due')->assertExitCode(0);

        Notification::assertSentTo($owner, MilestoneDueSoonNotification::class);
    }

    public function test_it_does_not_notify_for_a_milestone_far_in_the_future(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Future Project',
            'status' => 'active',
        ]);
        Milestone::create([
            'project_id' => $project->id,
            'title' => 'Far off milestone',
            'due_date' => now()->addMonths(2),
            'status' => 'pending',
        ]);

        $this->artisan('projects:check-milestones-due')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_it_does_not_notify_for_a_completed_milestone(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Completed Milestone Project',
            'status' => 'active',
        ]);
        Milestone::create([
            'project_id' => $project->id,
            'title' => 'Already done',
            'due_date' => now()->addDay(),
            'status' => 'completed',
        ]);

        $this->artisan('projects:check-milestones-due')->assertExitCode(0);

        Notification::assertNothingSent();
    }
}
