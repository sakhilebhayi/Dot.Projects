<?php

namespace Tests\Feature;

use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Projects\ProjectComments;
use App\Livewire\Projects\ProjectSettings;
use App\Livewire\Projects\TaskPanel;
use App\Livewire\Projects\WorkList;
use App\Models\Activity;
use App\Models\ChecklistItem;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectTask;
use App\Models\User;
use App\Notifications\NewCommentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P3a, the execution core: tasks edited in full with checklists and their own comments,
 * milestones renamed, reordered and deleted, the work table with filters, and projects edited,
 * archived (read-only), restored and duplicated.
 */
class ExecutionCoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
    }

    /** @return array{owner: User, editor: User, viewer: User, project: Project, ms: Milestone, task: ProjectTask} */
    private function world(): array
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Lerato Mokoena']);
        $team = $owner->currentTeam;
        $editor = User::factory()->create(['name' => 'Sipho Dlamini', 'current_team_id' => $team->id]);
        $viewer = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($editor, ['role' => 'editor']);
        $team->users()->attach($viewer, ['role' => 'viewer']);
        $project = Project::create(['team_id' => $team->id, 'owner_id' => $owner->id, 'name' => 'Fleet rollout', 'status' => 'active']);
        $ms = $project->milestones()->create(['title' => 'Kathu go-live', 'due_date' => '2026-11-30', 'status' => 'pending', 'sort_order' => 0]);
        $task = $project->tasks()->create(['title' => 'Clear customs', 'status' => 'todo', 'milestone_id' => $ms->id]);

        return ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'project' => $project->fresh(), 'ms' => $ms, 'task' => $task];
    }

    public function test_a_task_is_edited_in_full_and_the_log_keeps_no_text(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'project' => $project, 'task' => $task] = $this->world();
        $outsider = User::factory()->withPersonalTeam()->create();

        $panel = Livewire::actingAs($owner)->test(TaskPanel::class, ['project' => $project, 'task' => $task]);
        $panel->set('startDate', '2026-11-10')->set('dueDate', '2026-11-05')->call('save')->assertHasErrors('dueDate');
        $panel->set('dueDate', '2026-11-20')->set('assignee', (string) $outsider->id)->call('save')->assertHasErrors('assignee_id');
        $panel->set('assignee', (string) $editor->id)->set('title', 'Clear 18 units through customs')->set('description', 'Agent: ask for the bond release.')
            ->set('status', 'done')->set('priority', 'urgent')->set('estimateHours', '1.5')->call('save')->assertHasNoErrors();

        $task->refresh();
        $this->assertSame(['Clear 18 units through customs', 'done', 'urgent', $editor->id, 90, '2026-11-10', '2026-11-20'], [$task->title, $task->status, $task->priority, $task->assignee_id, $task->estimate_minutes, $task->start_date->toDateString(), $task->due_date->toDateString()]);
        $this->assertNotNull($task->completed_at);
        $this->assertSame('1 h 30 m', $task->estimateLabel());
        $this->assertSame('completed', $task->milestone->fresh()->status, 'its only task is done');

        $line = Activity::query()->where('action', 'task.updated')->sole();
        $this->assertSame(['from' => 'todo', 'to' => 'done'], $line->changes['status']);
        $this->assertStringNotContainsString('bond', json_encode($line->changes));
        $this->assertStringNotContainsString('18 units', json_encode($line->changes));

        Livewire::actingAs($viewer)->test(TaskPanel::class, ['project' => $project, 'task' => $task])->set('title', 'Nope')->call('save')->assertForbidden();
        $this->actingAs($owner)->get(route('projects.tasks.show', [$project, $task]))->assertOk()->assertSee('Clear 18 units through customs');
    }

    public function test_a_task_from_another_project_does_not_open_under_this_one(): void
    {
        ['owner' => $owner, 'project' => $project] = $this->world();
        $other = Project::create(['team_id' => $project->team_id, 'owner_id' => $owner->id, 'name' => 'Other', 'status' => 'active']);
        $foreign = $other->tasks()->create(['title' => 'Elsewhere', 'status' => 'todo']);

        $this->actingAs($owner)->get(route('projects.tasks.show', [$project, $foreign]))->assertNotFound();
    }

    public function test_checklists_task_comments_and_deleting_a_task(): void
    {
        Notification::fake();
        ['owner' => $owner, 'editor' => $editor, 'project' => $project, 'ms' => $ms, 'task' => $task] = $this->world();
        $task->update(['assignee_id' => $editor->id]);
        $done = $project->tasks()->create(['title' => 'Order units', 'status' => 'done', 'milestone_id' => $ms->id]);

        $panel = Livewire::actingAs($owner)->test(TaskPanel::class, ['project' => $project, 'task' => $task]);
        $panel->set('newItem', 'Call the agent')->call('addItem')->set('newItem', 'Pay the duty')->call('addItem');
        $first = ChecklistItem::query()->where('body', 'Call the agent')->sole();
        $panel->call('toggleItem', $first->id);
        $this->assertNotNull($first->fresh()->done_at);
        $panel->call('removeItem', ChecklistItem::query()->where('body', 'Pay the duty')->sole()->id);
        $this->assertSame(1, $task->checklist()->count());

        Livewire::actingAs($owner)->test(ProjectComments::class, ['project' => $project, 'task' => $task])->set('body', 'Agent says Thursday.')->call('addComment');
        $comment = ProjectComment::query()->sole();
        $this->assertSame([$task->getMorphClass(), $task->id], [$comment->commentable_type, $comment->commentable_id]);
        Notification::assertSentTo($editor, NewCommentNotification::class, fn ($n) => $n->toDatabase($editor)['url'] === route('projects.tasks.show', [$project, $task]));

        $panel->call('delete')->assertRedirect(route('projects.work', $project));
        $this->assertNull(ProjectTask::query()->find($task->id));
        $this->assertSame(0, ProjectComment::query()->count(), 'the task thread goes with it');
        $this->assertSame(0, ChecklistItem::query()->count());
        $this->assertSame('completed', $ms->fresh()->status, 'only the done task is left');
        $this->assertTrue(ProjectTask::query()->whereKey($done->id)->exists());
    }

    public function test_the_work_table_filters_and_changes_in_place_and_manages_milestones(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'project' => $project, 'ms' => $ms, 'task' => $task] = $this->world();
        $second = $project->milestones()->create(['title' => 'Hotazel go-live', 'status' => 'pending', 'sort_order' => 1]);
        $late = $project->tasks()->create(['title' => 'Print driver cards', 'status' => 'todo', 'due_date' => '2026-10-28', 'assignee_id' => $editor->id, 'milestone_id' => $second->id]);
        $project->tasks()->create(['title' => 'Kick-off', 'status' => 'done']);

        $list = Livewire::actingAs($owner)->test(WorkList::class, ['project' => $project]);
        $this->assertSame(3, $list->instance()->tasks->count());
        $list->set('lateOnly', true);
        $this->assertSame([$late->id], $list->instance()->tasks->pluck('id')->all());
        $list->set('lateOnly', false)->set('status', 'open')->set('who', 'none');
        $this->assertSame([$task->id], $list->instance()->tasks->pluck('id')->all());
        $list->call('clearFilters')->set('search', 'driver');
        $this->assertSame([$late->id], $list->instance()->tasks->pluck('id')->all());

        $list->call('setStatus', $late->id, 'in_progress');
        $this->assertSame('in_progress', $late->fresh()->status);
        Livewire::actingAs($owner)->test(WorkList::class, ['project' => $project])->call('setStatus', $late->id, 'shipped')->assertStatus(422);
        $list = Livewire::actingAs($owner)->test(WorkList::class, ['project' => $project]);

        $list->call('editMilestone', $ms->id)->set('milestoneTitle', 'Kathu live')->set('milestoneDue', '2026-12-04')->call('saveMilestone');
        $this->assertSame(['Kathu live', '2026-12-04'], [$ms->fresh()->title, $ms->fresh()->due_date->toDateString()]);
        $list->call('moveMilestone', $second->id, 'up');
        $this->assertSame(['Hotazel go-live', 'Kathu live'], $project->milestones()->pluck('title')->all());

        $list->set('deletingMilestone', $ms->id)->call('deleteMilestone');
        $this->assertNull(Milestone::query()->find($ms->id));
        $this->assertNull($task->fresh()->milestone_id, 'the task stays, without a milestone');

        $this->actingAs($owner)->get(route('projects.work', $project))->assertOk()->assertSee('Print driver cards')->assertSee('No milestone');
    }

    public function test_projects_are_edited_archived_restored_and_duplicated(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'project' => $project, 'ms' => $ms, 'task' => $task] = $this->world();
        $task->update(['assignee_id' => $editor->id, 'status' => 'in_progress', 'due_date' => '2026-11-20']);
        ChecklistItem::create(['project_task_id' => $task->id, 'body' => 'Call the agent']);

        Livewire::actingAs($editor)->test(ProjectSettings::class, ['project' => $project])
            ->set('name', 'Fleet system rollout')->set('status', 'on_hold')->set('dueDate', '2027-02-12')->call('save')->assertHasNoErrors();
        $this->assertSame(['Fleet system rollout', 'on_hold', '2027-02-12'], [$project->fresh()->name, $project->fresh()->status, $project->fresh()->due_date->toDateString()]);

        // An editor who does not own the project cannot archive it; the owner can.
        Livewire::actingAs($editor)->test(ProjectSettings::class, ['project' => $project])->call('archive')->assertForbidden();
        Livewire::actingAs($owner)->test(ProjectSettings::class, ['project' => $project->fresh()])->call('archive');
        $archived = $project->fresh();
        $this->assertTrue($archived->isArchived());

        $this->assertFalse($editor->can('update', $archived));
        $this->assertFalse($editor->can('update', $task->fresh()));
        Livewire::actingAs($editor)->test(ProjectBoard::class, ['project' => $archived])->set('newTaskTitle', 'Sneaky')->call('addTask')->assertForbidden();
        $this->actingAs($owner)->get(route('dashboard'))->assertDontSee('Fleet system rollout')->assertSee('1 archived');
        $this->actingAs($owner)->get(route('dashboard', ['status' => 'archived']))->assertSee('Fleet system rollout');
        $this->actingAs($owner)->get(route('projects.show', $archived))->assertOk()->assertSee('Everything is kept and read-only.');

        Livewire::actingAs($owner)->test(ProjectSettings::class, ['project' => $archived])->call('restore');
        $this->assertFalse($project->fresh()->isArchived());

        Livewire::actingAs($viewer)->test(ProjectSettings::class, ['project' => $project->fresh()])->call('duplicate')->assertForbidden();
        Livewire::actingAs($editor)->test(ProjectSettings::class, ['project' => $project->fresh()])->set('copyName', 'Fleet rollout, Postmasburg')->call('duplicate');
        $copy = Project::query()->where('name', 'Fleet rollout, Postmasburg')->sole();
        $copiedTask = $copy->tasks()->sole();
        $this->assertSame([$editor->id, 'planning'], [$copy->owner_id, $copy->status]);
        $this->assertSame(['Clear customs', 'backlog', $editor->id, '2026-11-20', 'Kathu go-live'], [$copiedTask->title, $copiedTask->status, $copiedTask->assignee_id, $copiedTask->due_date->toDateString(), $copiedTask->milestone->title]);
        $this->assertNotSame($ms->id, $copiedTask->milestone_id);
        $this->assertSame(['Call the agent'], $copiedTask->checklist()->pluck('body')->all());
        $this->assertSame(0, $copy->comments()->count());
    }
}
