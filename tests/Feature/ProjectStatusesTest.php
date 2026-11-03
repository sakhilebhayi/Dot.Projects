<?php

namespace Tests\Feature;

use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Projects\ProjectSettings;
use App\Livewire\Projects\WorkList;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\ActivityFeed;
use App\Services\ProjectStatuses;
use App\Services\ProjectTemplates;
use App\Services\ProjectWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** R-34 P13c: a project names its own statuses; the five categories keep every rule's meaning. */
class ProjectStatusesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->actingAs($this->owner);
        $this->project = Project::create(['team_id' => $this->owner->currentTeam->id, 'owner_id' => $this->owner->id, 'name' => 'Clinic', 'status' => 'active']);
    }

    private function statuses(): ProjectStatuses
    {
        return app(ProjectStatuses::class);
    }

    private function key(string $name): string
    {
        return $this->statuses()->for($this->project->fresh())->firstWhere('name', $name)['key'];
    }

    public function test_a_project_starts_with_the_standard_five_and_customising_keeps_every_task_in_place(): void
    {
        $this->assertSame(['Backlog', 'To do', 'In progress', 'In review', 'Done'], $this->statuses()->for($this->project)->pluck('name')->all());
        $done = $this->project->tasks()->create(['title' => 'Brief', 'status' => 'done', 'priority' => 'medium']);
        $open = $this->project->tasks()->create(['title' => 'Drawings', 'status' => 'in_progress', 'priority' => 'medium']);

        $this->statuses()->customise($this->project, $this->owner);

        $this->assertSame('Done', $this->project->statuses()->find($done->fresh()->status_id)->name);
        $this->assertSame('in_progress', $open->fresh()->status);
        $this->assertSame($this->key('In progress'), 's'.$open->fresh()->status_id);
    }

    public function test_a_new_status_sits_with_its_meaning_and_moving_there_keeps_the_rules(): void
    {
        $this->statuses()->add($this->project, $this->owner, 'Awaiting client', 'review');
        $this->assertSame(['Backlog', 'To do', 'In progress', 'In review', 'Awaiting client', 'Done'], $this->statuses()->for($this->project->fresh())->pluck('name')->all());

        $first = $this->project->tasks()->create(['title' => 'Survey', 'status' => 'todo', 'priority' => 'medium']);
        $blocked = $this->project->tasks()->create(['title' => 'Design', 'status' => 'todo', 'priority' => 'medium']);
        app(ProjectWork::class)->addDependency($blocked, $first, $this->owner);

        Livewire::test(ProjectBoard::class, ['project' => $this->project->fresh()])->call('moveTask', $first->id, $this->key('Awaiting client'))->assertHasNoErrors();
        $first->refresh();
        $this->assertSame(['review', $this->key('Awaiting client')], [$first->status, 's'.$first->status_id], 'the category is what rules read');
        $this->assertDatabaseHas('activities', ['action' => 'task.moved', 'subject_id' => $first->id]);

        $this->expectException(ValidationException::class);
        app(ProjectWork::class)->updateTask($blocked->fresh(), ['status' => $this->key('Awaiting client')], $this->owner);
    }

    public function test_the_board_shows_the_project_s_columns_and_refuses_anything_else(): void
    {
        $this->statuses()->add($this->project, $this->owner, 'QA', 'review');
        $t = $this->project->tasks()->create(['title' => 'Survey', 'status' => 'todo', 'priority' => 'medium']);

        $this->get(route('projects.board', $this->project))->assertOk()->assertSeeInOrder(['In review', 'QA', 'Done']);
        Livewire::test(ProjectBoard::class, ['project' => $this->project->fresh()])->call('moveTask', $t->id, 's999999')->assertStatus(422);
    }

    public function test_renaming_and_reordering_change_no_task_and_a_category_key_still_works(): void
    {
        $this->statuses()->customise($this->project, $this->owner);
        $t = $this->project->tasks()->create(['title' => 'Survey', 'status' => 'todo', 'priority' => 'medium']);
        $review = $this->project->statuses()->where('category', 'review')->first();
        $this->statuses()->rename($review, $this->owner, 'Client check');
        $this->statuses()->move($review, -1);

        $this->assertSame(['Backlog', 'To do', 'Client check', 'In progress', 'Done'], $this->statuses()->for($this->project->fresh())->pluck('name')->all());
        app(ProjectWork::class)->updateTask($t, ['status' => 'done'], $this->owner);
        $this->assertSame(['done', $this->project->statuses()->where('category', 'done')->value('id')], [$t->fresh()->status, $t->fresh()->status_id]);
        $this->assertNotNull($t->fresh()->completed_at);
    }

    public function test_removing_moves_the_tasks_and_the_workflow_always_keeps_done_and_not_done(): void
    {
        $qa = $this->statuses()->add($this->project, $this->owner, 'QA', 'review');
        $t = $this->project->tasks()->create(['title' => 'Survey', 'status' => 'todo', 'priority' => 'medium']);
        app(ProjectWork::class)->updateTask($t, ['status' => 's'.$qa->id], $this->owner);

        $this->statuses()->remove($qa, $this->owner, $this->key('Done'));
        $this->assertSame('done', $t->fresh()->status);
        $this->assertNull($this->project->statuses()->find($qa->id));

        $done = $this->project->statuses()->where('category', 'done')->first();
        try {
            $this->statuses()->remove($done, $this->owner, $this->key('Backlog'));
            $this->fail('removed the last done status');
        } catch (ValidationException) {
        }
        $this->statuses()->reset($this->project->fresh(), $this->owner);
        $this->assertSame(0, $this->project->statuses()->count());
        $this->assertSame(['done', null], [$t->fresh()->status, $t->fresh()->status_id], 'a reset keeps what each task means');
    }

    public function test_lists_filter_and_the_history_name_the_project_s_statuses(): void
    {
        $qa = $this->statuses()->add($this->project, $this->owner, 'QA', 'review');
        $t = $this->project->tasks()->create(['title' => 'Survey', 'status' => 'todo', 'priority' => 'medium']);
        $this->project->tasks()->create(['title' => 'Other review', 'status' => 'review', 'priority' => 'medium']);
        app(ProjectWork::class)->updateTask($t->fresh(), ['status' => 's'.$qa->id], $this->owner);

        Livewire::test(WorkList::class, ['project' => $this->project->fresh()])->set('status', 's'.$qa->id)->assertSee('Survey')->assertDontSee('Other review');
        $this->statuses()->rename($qa->fresh(), $this->owner, 'Quality check');
        $feed = collect(app(ActivityFeed::class)->forProject($this->project->fresh(), 20))->pluck('text')->join(' ');
        $this->assertStringContainsString('to Quality check', $feed, 'the history shows the current name of the status');
    }

    public function test_settings_manage_the_workflow_for_managers_only(): void
    {
        Livewire::test(ProjectSettings::class, ['project' => $this->project])->call('customiseWorkflow')
            ->set('newStatusName', 'Ready to invoice')->set('newStatusCategory', 'done')->call('addStatus')->assertHasNoErrors()
            ->assertSee('Ready to invoice')->set('newStatusName', 'ready to INVOICE')->call('addStatus')->assertHasErrors('workflow');

        $member = User::factory()->create(['current_team_id' => $this->owner->currentTeam->id]);
        $this->owner->currentTeam->users()->attach($member, ['role' => 'editor']);
        $this->actingAs($member);
        Livewire::test(ProjectSettings::class, ['project' => $this->project->fresh()])->call('resetWorkflow')->assertForbidden();
    }

    public function test_templates_and_copies_bring_the_workflow(): void
    {
        $this->statuses()->add($this->project, $this->owner, 'Awaiting client', 'review');
        $this->project->tasks()->create(['title' => 'Survey', 'status' => 'todo', 'priority' => 'medium']);

        $template = app(ProjectTemplates::class)->capture($this->project->fresh(), $this->owner, 'Clinic way', null);
        $made = app(ProjectTemplates::class)->create($this->owner->currentTeam, $this->owner, 'team:'.$template->id, ['name' => 'Clinic 2', 'start' => Carbon::parse('2027-01-04')]);
        $this->assertContains('Awaiting client', $made->statuses()->pluck('name')->all());
        $this->assertSame($made->statuses()->where('category', 'backlog')->value('id'), $made->tasks()->first()->status_id);

        $copy = app(ProjectWork::class)->duplicate($this->project->fresh(), $this->owner, 'Clinic copy');
        $this->assertSame($this->project->statuses()->pluck('name')->all(), $copy->statuses()->pluck('name')->all());
        $this->assertNotNull($copy->tasks()->first()->status_id);
        $this->assertInstanceOf(ProjectTask::class, $copy->tasks()->first());
    }
}
