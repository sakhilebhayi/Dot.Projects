<?php

namespace Tests\Feature;

use App\Livewire\Projects\ProjectComments;
use App\Models\Project;
use App\Models\User;
use App\Notifications\NewCommentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectCommentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_team_member_can_post_a_comment_and_the_owner_is_notified(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Comment Project',
            'status' => 'active',
        ]);

        Livewire::actingAs($member)
            ->test(ProjectComments::class, ['project' => $project])
            ->set('body', 'Looks good, shipping tomorrow.')
            ->call('addComment')
            ->assertSet('body', '');

        $this->assertDatabaseHas('project_comments', [
            'commentable_type' => Project::class,
            'commentable_id' => $project->id,
            'user_id' => $member->id,
            'body' => 'Looks good, shipping tomorrow.',
        ]);

        Notification::assertSentTo($owner, NewCommentNotification::class);
    }

    public function test_the_owner_commenting_on_their_own_project_does_not_self_notify(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Self Comment Project',
            'status' => 'active',
        ]);

        Livewire::actingAs($owner)
            ->test(ProjectComments::class, ['project' => $project])
            ->set('body', 'Note to self.')
            ->call('addComment');

        Notification::assertNothingSent();
    }
}
