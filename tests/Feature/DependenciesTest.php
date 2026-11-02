<?php

namespace Tests\Feature;

use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Projects\ProjectSettings;
use App\Livewire\Projects\TaskPanel;
use App\Livewire\Projects\WorkList;
use App\Models\Project;
use App\Models\ProjectLabel;
use App\Models\ProjectTask;
use App\Models\SavedView;
use App\Models\User;
use App\Services\ProjectWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P3b: finish-to-start dependencies with loop prevention and a blocked state that stops
 * started work, one level of subtasks, labels and saved views, and duplicate carrying them all.
 */
class DependenciesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
    }

    /** @return array{owner: User, viewer: User, project: Project, a: ProjectTask, b: ProjectTask, c: ProjectTask} */
    private function chain(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $viewer = User::factory()->create(['current_team_id' => $owner->currentTeam->id]);
        $owner->currentTeam->users()->attach($viewer, ['role' => 'viewer']);
        $project = Project::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => 'Fleet rollout', 'status' => 'active']);
        $a = $project->tasks()->create(['title' => 'Clear customs', 'status' => 'todo', 'priority' => 'medium']);
        $b = $project->tasks()->create(['title' => 'Fit the trucks', 'status' => 'backlog', 'priority' => 'medium']);
        $c = $project->tasks()->create(['title' => 'Go live at Kathu', 'status' => 'backlog', 'priority' => 'medium']);

        return ['owner' => $owner, 'viewer' => $viewer, 'project' => $project->fresh(), 'a' => $a->fresh(), 'b' => $b->fresh(), 'c' => $c->fresh()];
    }

    public function test_dependencies_are_checked_and_never_make_a_loop(): void
    {
        ['owner' => $owner, 'viewer' => $viewer, 'project' => $project, 'a' => $a, 'b' => $b, 'c' => $c] = $this->chain();

        $panel = fn (ProjectTask $t, ?User $u = null) => Livewire::actingAs($u ?? $owner)->test(TaskPanel::class, ['project' => $project, 'task' => $t]);
        $panel($b)->set('newDependency', (string) $a->id)->call('addDependency')->assertHasNoErrors();
        $panel($c)->set('newDependency', (string) $b->id)->call('addDependency')->assertHasNoErrors();
        $panel($c)->set('newDependency', (string) $b->id)->call('addDependency')->assertHasErrors('dependency');
        $panel($a)->set('newDependency', (string) $c->id)->call('addDependency')->assertHasErrors('dependency');
        $this->assertSame(0, $a->predecessors()->count(), 'Go live waits on Fit, which waits on Clear: Clear cannot wait on Go live');

        // Since R-34 P8b a task may wait on another project of the same business (CrossProjectTest);
        // another business's work stays out of reach.
        $stranger = User::factory()->withPersonalTeam()->create();
        $other = Project::withoutTeamScope(fn () => Project::create(['team_id' => $stranger->currentTeam->id, 'owner_id' => $stranger->id, 'name' => 'Other', 'status' => 'active']));
        $foreign = Project::withoutTeamScope(fn () => $other->tasks()->create(['title' => 'Elsewhere', 'status' => 'todo']));
        $this->actingAs($owner);
        try {
            app(ProjectWork::class)->addDependency($b, $foreign, $owner);
            $this->fail('a dependency across businesses was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('dependency', $e->errors());
        }
        $this->assertSame(1, $b->predecessors()->count());

        $panel($b, $viewer)->set('newDependency', (string) $c->id)->call('addDependency')->assertForbidden();
    }

    public function test_a_blocked_task_cannot_start_until_what_it_waits_for_is_done(): void
    {
        ['owner' => $owner, 'project' => $project, 'a' => $a, 'b' => $b] = $this->chain();
        $this->actingAs($owner);
        app(ProjectWork::class)->addDependency($b, $a, $owner);
        $this->assertTrue($b->fresh()->isBlocked());

        Livewire::actingAs($owner)->test(ProjectBoard::class, ['project' => $project])->call('moveTask', $b->id, 'in_progress')->assertHasErrors('status');
        $this->assertSame('backlog', $b->fresh()->status);
        Livewire::actingAs($owner)->test(ProjectBoard::class, ['project' => $project])->call('moveTask', $b->id, 'todo')->assertHasNoErrors();

        Livewire::actingAs($owner)->test(TaskPanel::class, ['project' => $project, 'task' => $b->fresh()])->set('status', 'done')->call('save')->assertHasErrors('status');
        Livewire::actingAs($owner)->test(WorkList::class, ['project' => $project])->call('setStatus', $b->id, 'review')->assertHasErrors('status');

        $this->actingAs($owner)->get(route('projects.show', $project))->assertSee('1 blocked')->assertSee('waits for Clear customs');
        $this->actingAs($owner)->get(route('projects.board', $project))->assertSee('Blocked');

        Livewire::actingAs($owner)->test(ProjectBoard::class, ['project' => $project])->call('moveTask', $a->id, 'done');
        $this->assertFalse($b->fresh()->isBlocked());
        Livewire::actingAs($owner)->test(ProjectBoard::class, ['project' => $project])->call('moveTask', $b->id, 'in_progress')->assertHasNoErrors();
        $this->assertSame('in_progress', $b->fresh()->status);
    }

    public function test_subtasks_labels_and_saved_views(): void
    {
        ['owner' => $owner, 'viewer' => $viewer, 'project' => $project, 'a' => $a] = $this->chain();
        $ms = $project->milestones()->create(['title' => 'Kathu go-live', 'status' => 'pending']);
        $a->update(['milestone_id' => $ms->id]);

        $panel = Livewire::actingAs($owner)->test(TaskPanel::class, ['project' => $project, 'task' => $a->fresh()]);
        $panel->set('newSubtask', 'Book the clearing agent')->call('addSubtask')->assertHasNoErrors();
        $sub = ProjectTask::query()->where('title', 'Book the clearing agent')->sole();
        $this->assertSame([$a->id, $ms->id, 'backlog'], [$sub->parent_id, $sub->milestone_id, $sub->status]);
        Livewire::actingAs($owner)->test(TaskPanel::class, ['project' => $project, 'task' => $sub])->set('newSubtask', 'Too deep')->call('addSubtask')->assertHasErrors('subtask');

        $panel->set('newLabel', 'Customs')->call('addLabel')->set('newLabel', '  customs ')->call('addLabel');
        $this->assertSame(1, ProjectLabel::query()->count(), 'the same label in any letter case');
        $label = ProjectLabel::query()->sole();

        $list = Livewire::actingAs($owner)->test(WorkList::class, ['project' => $project]);
        $list->set('label', (string) $label->id);
        $this->assertSame([$a->id], $list->instance()->tasks->pluck('id')->all());
        $list->set('viewName', 'Customs work')->call('saveView');
        $view = SavedView::query()->sole();
        $this->assertSame(['label' => (string) $label->id], $view->filters);

        $fresh = Livewire::actingAs($owner)->test(WorkList::class, ['project' => $project])->call('applyView', $view->id);
        $this->assertSame((string) $label->id, $fresh->get('label'));
        Livewire::actingAs($viewer)->test(WorkList::class, ['project' => $project])->call('applyView', $view->id)->assertNotFound();
        $this->actingAs($owner)->get(route('projects.work', $project))->assertSee('Part of Clear customs');

        $panel->call('removeLabel', $label->id);
        $this->assertSame(0, $a->labels()->count());
    }

    public function test_duplicate_keeps_subtasks_labels_and_dependencies(): void
    {
        ['owner' => $owner, 'project' => $project, 'a' => $a, 'b' => $b] = $this->chain();
        $this->actingAs($owner);
        $work = app(ProjectWork::class);
        $work->addDependency($b, $a, $owner);
        $work->addSubtask($a, 'Book the clearing agent');
        $work->addLabel($a, 'Customs');

        Livewire::actingAs($owner)->test(ProjectSettings::class, ['project' => $project])->set('copyName', 'Fleet rollout, round two')->call('duplicate');
        $copy = Project::query()->where('name', 'Fleet rollout, round two')->sole();
        $ca = $copy->tasks()->where('title', 'Clear customs')->sole();
        $cb = $copy->tasks()->where('title', 'Fit the trucks')->sole();

        $this->assertSame([$ca->id], $cb->predecessors()->pluck('project_tasks.id')->all());
        $this->assertSame($ca->id, $copy->tasks()->where('title', 'Book the clearing agent')->sole()->parent_id);
        $this->assertSame(['Customs'], $ca->labels()->pluck('name')->all());
        $this->assertNotSame($a->labels()->value('project_labels.id'), $ca->labels()->value('project_labels.id'));
    }
}
