<?php

namespace Tests\Feature;

use App\Livewire\Projects\CreateProject;
use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Projects\ProjectComments;
use App\Models\Activity;
use App\Models\AiPlanLog;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Notifications\MilestoneDueSoonNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P1 foundations: the team scope fails closed, policies do not depend on who is signed in,
 * the board refuses unknown columns, an example plan is never passed off as an AI plan, every
 * change lands in the activity log without free text, and the workspace no longer compiles
 * Tailwind from a CDN.
 */
class FoundationsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Project} */
    private function project(string $name = 'Fleet rollout'): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => $name, 'status' => 'active']);

        return [$owner, $project];
    }

    public function test_the_team_scope_fails_closed_and_is_bypassed_only_on_purpose(): void
    {
        [$owner, $project] = $this->project();
        [$other] = $this->project('Other business');

        Auth::logout();
        $this->assertSame(0, Project::query()->count(), 'nobody signed in sees nothing');
        $this->assertSame(2, Project::withoutTeamScope(fn () => Project::query()->count()));

        $this->actingAs($owner);
        $this->assertSame([$project->id], Project::query()->pluck('id')->all());

        // A current team the user does not belong to (a stale pointer) shows nothing.
        $owner->forceFill(['current_team_id' => $other->currentTeam->id])->save();
        $owner->unsetRelation('currentTeam');
        $this->assertSame(0, Project::query()->count());
    }

    public function test_policies_check_membership_whoever_is_signed_in(): void
    {
        [$owner, $project] = $this->project();
        [$outsider] = $this->project('Other business');
        $task = $project->tasks()->create(['title' => 'Survey the sites', 'status' => 'todo']);

        Auth::logout();
        $this->assertTrue($owner->can('view', $task));
        $this->assertTrue($owner->can('update', $task));
        $this->assertFalse($outsider->can('view', $task));

        $this->actingAs($outsider);
        $this->assertTrue($owner->can('update', $task), 'the outsider being signed in changes nothing for the owner');
    }

    public function test_the_board_refuses_a_status_that_is_not_one_of_its_columns(): void
    {
        [$owner, $project] = $this->project();
        $task = $project->tasks()->create(['title' => 'Survey the sites', 'status' => 'todo']);

        Livewire::actingAs($owner)->test(ProjectBoard::class, ['project' => $project])
            ->call('moveTask', $task->id, 'shipped')->assertStatus(422);
        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_without_an_ai_key_the_plan_is_offered_and_saved_as_an_example(): void
    {
        config(['services.anthropic.api_key' => '']);
        $owner = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($owner)->test(CreateProject::class)
            ->assertSee('Create with an example plan')->assertSee('not tailored to this project')
            ->set('name', 'Fleet rollout')->call('generatePlan')->assertRedirect();

        $project = Project::withoutTeamScope(fn () => Project::query()->sole());
        $milestones = Milestone::query()->where('project_id', $project->id)->get();
        $this->assertCount(3, $milestones);
        $this->assertTrue($milestones->every(fn ($m) => str_starts_with((string) $m->description, 'Example plan, not tailored')));
        $this->assertSame(0, AiPlanLog::query()->count(), 'no AI was involved');
        $this->assertSame('warning', session('flash.bannerStyle'));
        $this->assertTrue(Activity::withoutTeamScope(fn () => Activity::query()->where('action', 'project.example_plan_added')->exists()));
    }

    public function test_with_a_key_the_ai_plan_is_saved_and_a_failure_saves_nothing(): void
    {
        config(['services.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['text' => json_encode(['milestones' => [['title' => 'Survey', 'tasks' => [['title' => 'Visit site A', 'priority' => 'critical']]]]])]], 'usage' => ['output_tokens' => 42]])
            ->push(['error' => 'overloaded'], 529)]);
        $owner = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($owner)->test(CreateProject::class)
            ->assertSee('Create and draft a plan with AI')
            ->set('name', 'Fleet rollout')->call('generatePlan')->assertRedirect();
        $task = Project::withoutTeamScope(fn () => Project::query()->sole())->tasks()->sole();
        $this->assertSame(['Visit site A', 'medium'], [$task->title, $task->priority], 'an unknown priority falls back to medium');
        $this->assertSame(1, AiPlanLog::query()->count());

        Livewire::actingAs($owner)->test(CreateProject::class)->set('name', 'Second')->call('generatePlan')->assertRedirect();
        $second = Project::withoutTeamScope(fn () => Project::query()->where('name', 'Second')->sole());
        $this->assertSame(0, $second->milestones()->count());
        $this->assertSame('warning', session('flash.bannerStyle'));
    }

    public function test_plan_drafting_is_limited_per_person_per_hour(): void
    {
        config(['services.anthropic.api_key' => '']);
        $owner = User::factory()->withPersonalTeam()->create();

        foreach (range(1, 10) as $i) {
            Livewire::actingAs($owner)->test(CreateProject::class)->set('name', "P$i")->call('generatePlan');
        }
        Livewire::actingAs($owner)->test(CreateProject::class)->set('name', 'One too many')->call('generatePlan')
            ->assertSet('aiError', 'You have drafted several plans in the last hour. Create the project now and try again later.');
        $this->assertSame(10, Project::withoutTeamScope(fn () => Project::query()->count()));
    }

    public function test_changes_land_in_the_activity_log_without_free_text(): void
    {
        [$owner, $project] = $this->project();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);
        $milestone = $project->milestones()->create(['title' => 'Survey', 'status' => 'pending']);
        $task = $project->tasks()->create(['title' => 'Visit site A', 'status' => 'todo', 'milestone_id' => $milestone->id]);

        $board = Livewire::actingAs($owner)->test(ProjectBoard::class, ['project' => $project]);
        $board->call('assignTask', $task->id, $member->id)->call('moveTask', $task->id, 'done');
        Livewire::actingAs($owner)->test(ProjectComments::class, ['project' => $project])->set('body', 'Site A is fenced off until Friday.')->call('addComment');
        $board->set('closureRejectReason', 'Snag list still open at site B')->call('confirmRejectClosure');

        $lines = Activity::query()->where('project_id', $project->id)->orderBy('id')->get();
        $this->assertSame(['task.assigned', 'task.moved', 'milestone.status_changed', 'project.closure_proposed', 'comment.added', 'project.closure_rejected'], $lines->pluck('action')->all());
        $this->assertSame(['from' => 'todo', 'to' => 'done'], $lines[1]->changes['status']);
        $this->assertSame($owner->id, $lines[0]->user_id);
        $all = json_encode($lines->pluck('changes'));
        $this->assertStringNotContainsString('fenced', $all);
        $this->assertStringNotContainsString('Snag', $all);
    }

    public function test_reminders_still_run_with_nobody_signed_in(): void
    {
        [$owner, $project] = $this->project();
        $project->milestones()->create(['title' => 'Survey', 'status' => 'pending', 'due_date' => now()->addDay()->toDateString()]);

        Auth::logout();
        $this->artisan('projects:check-milestones-due')->assertExitCode(0);
        $this->assertSame(1, $owner->notifications()->where('type', MilestoneDueSoonNotification::class)->count());
    }

    public function test_the_workspace_uses_the_built_stylesheet_and_loaded_icon_font(): void
    {
        [$owner, $project] = $this->project();

        $page = $this->actingAs($owner)->get(route('projects.show', $project))->assertOk();
        // Tests run without Vite, so the built link itself is not rendered here; the CDN must be gone.
        $page->assertDontSee('cdn.tailwindcss.com')->assertDontSee('material-symbols-outlined');
        $this->actingAs($owner)->get(route('projects.create'))->assertOk()->assertDontSee('material-symbols-outlined');
    }
}
