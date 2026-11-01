<?php

namespace Tests\Feature;

use App\Livewire\Projects\ProjectBoard;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\ActivityFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P2, The Programme: the projects ledger, the overview's finding sentence and "When we land",
 * quick add on the board, and the activity feed in plain sentences. Every figure comes from records.
 */
class ProgrammeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
    }

    /** @return array{0: User, 1: Project} */
    private function project(array $attrs = []): array
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Lerato Mokoena']);
        $project = Project::create($attrs + ['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => 'Fleet rollout', 'status' => 'active', 'start_date' => '2026-09-14', 'due_date' => '2027-02-12']);

        return [$owner, $project];
    }

    public function test_the_projects_ledger_shows_next_milestone_progress_and_overdue_work(): void
    {
        [$owner, $late] = $this->project();
        $late->milestones()->create(['title' => 'Kathu go-live', 'due_date' => '2026-11-30', 'status' => 'pending']);
        $late->tasks()->create(['title' => 'Clear customs', 'status' => 'todo', 'due_date' => '2026-10-30']);
        $late->tasks()->create(['title' => 'Order units', 'status' => 'done']);
        Project::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => 'Website', 'status' => 'planning']);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('1 of 2 open projects')
            ->assertSee('Kathu go-live')->assertSee('30 Nov 2026')
            ->assertSee('1 of 2 tasks done', false)
            ->assertSee('None dated')
            ->assertSee('New project');
    }

    public function test_the_finding_sentence_follows_the_most_pressing_fact(): void
    {
        [$owner, $project] = $this->project();
        $this->actingAs($owner)->get(route('projects.show', $project))->assertSee('No milestones yet. Add the first one to see when this project lands.')->assertSee('Add a milestone');

        $ms = $project->milestones()->create(['title' => 'Kathu go-live', 'due_date' => '2026-11-30', 'status' => 'pending']);
        $project->tasks()->create(['title' => 'Fit trucks', 'status' => 'todo', 'milestone_id' => $ms->id]);
        $this->actingAs($owner)->get(route('projects.show', $project))->assertSee('Next milestone: <b>Kathu go-live</b> on 30 Nov, with 0 of 1 task done. Nothing is overdue.', false);

        $project->tasks()->create(['title' => 'Clear customs', 'status' => 'todo', 'due_date' => '2026-10-23']);
        $this->actingAs($owner)->get(route('projects.show', $project))->assertSee('<b>1 task</b> is overdue, the oldest by 10 days.', false)->assertSee('10 days late');

        $ms->forceFill(['due_date' => '2026-10-30'])->save();
        $this->actingAs($owner)->get(route('projects.show', $project))->assertSee('<b>Kathu go-live</b> was due 30 Oct, 3 days ago; 1 of its 1 task is still open.', false);

        $project->forceFill(['status' => 'pending_closure_approval'])->save();
        $this->actingAs($owner)->get(route('projects.show', $project))->assertSee('Every milestone is complete. Lerato Mokoena needs to confirm closing the project.')->assertSee('Review closure');
    }

    public function test_when_we_land_draws_dated_milestones_and_the_due_date(): void
    {
        [$owner, $project] = $this->project();
        $this->actingAs($owner)->get(route('projects.show', $project))->assertSee('No milestone has a date yet')->assertSee('in 102 days');

        $project->milestones()->create(['title' => 'Kathu go-live', 'due_date' => '2026-11-30', 'status' => 'pending']);
        $project->milestones()->create(['title' => 'Contract signed', 'due_date' => '2026-09-25', 'status' => 'completed']);
        $page = $this->actingAs($owner)->get(route('projects.show', $project));
        $page->assertSee('Milestones on a date line from 1 Sep 2026 to 28 Feb 2027')->assertSee('class="ms done"', false)->assertSee('12 Feb');

        $project->forceFill(['due_date' => '2026-10-31'])->save();
        $this->actingAs($owner)->get(route('projects.show', $project))->assertSee('2 days past due');
    }

    public function test_quick_add_creates_tasks_and_milestones_for_editors_only(): void
    {
        [$owner, $project] = $this->project();
        $done = $project->milestones()->create(['title' => 'Survey', 'status' => 'completed']);
        $project->tasks()->create(['title' => 'Walk the pit', 'status' => 'done', 'milestone_id' => $done->id]);
        [$other, $elsewhere] = $this->project(['name' => 'Elsewhere']);
        $foreign = Milestone::create(['project_id' => $elsewhere->id, 'title' => 'Not yours']);

        $board = Livewire::actingAs($owner)->test(ProjectBoard::class, ['project' => $project]);
        $board->set('newTaskTitle', 'Map the haul roads')->set('newTaskMilestone', (string) $foreign->id)->call('addTask')->assertHasErrors('newTaskMilestone');
        $board->set('newTaskTitle', 'Map the haul roads')->set('newTaskMilestone', (string) $done->id)->set('newTaskDue', '2026-11-20')->call('addTask')->assertHasNoErrors();

        $task = ProjectTask::query()->where('title', 'Map the haul roads')->sole();
        $this->assertSame(['backlog', $done->id, '2026-11-20'], [$task->status, $task->milestone_id, $task->due_date->toDateString()]);
        $this->assertSame('in_progress', $done->fresh()->status, 'new work reopens a completed milestone');

        $board->set('newMilestoneTitle', 'Kathu go-live')->set('newMilestoneDue', '2026-11-30')->call('addMilestone')->assertHasNoErrors();
        $this->assertSame('2026-11-30', $project->milestones()->where('title', 'Kathu go-live')->sole()->due_date->toDateString());

        $actions = Activity::query()->where('project_id', $project->id)->pluck('action')->all();
        $this->assertContains('task.created', $actions);
        $this->assertContains('milestone.created', $actions);

        $viewer = User::factory()->create();
        $owner->currentTeam->users()->attach($viewer, ['role' => 'viewer']);
        $viewer->forceFill(['current_team_id' => $owner->currentTeam->id])->save();
        Livewire::actingAs($viewer)->test(ProjectBoard::class, ['project' => $project->fresh()])->set('newTaskTitle', 'Sneaky')->call('addTask')->assertForbidden();
        $this->assertFalse(ProjectTask::query()->where('title', 'Sneaky')->exists());
    }

    public function test_the_activity_feed_reads_as_sentences_and_outsiders_cannot_open_the_board(): void
    {
        [$owner, $project] = $this->project();
        $task = $project->tasks()->create(['title' => 'Clear customs', 'status' => 'todo']);
        Livewire::actingAs($owner)->test(ProjectBoard::class, ['project' => $project])->call('moveTask', $task->id, 'done');

        $this->actingAs($owner);
        $line = ActivityFeed::forProject($project)[0];
        $this->assertSame(['Lerato Mokoena', 'moved “Clear customs” from To do to Done'], [$line['who'], $line['text']]);
        $this->get(route('projects.show', $project))->assertSee('moved “Clear customs” from To do to Done');

        [$outsider] = $this->project(['name' => 'Theirs']);
        $this->actingAs($outsider)->get(route('projects.board', $project))->assertNotFound();
    }
}
