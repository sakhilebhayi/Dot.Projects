<?php

namespace Tests\Feature;

use App\Livewire\Projects\CreateProject;
use App\Livewire\Projects\PeoplePanel;
use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Projects\TaskPanel;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use App\Services\Governance;
use App\Services\ProjectPeople;
use App\Services\ProjectWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P6a: project roles and private projects. Open projects behave as before; a private project
 * is seen only by its owner, its people and the business owner, everywhere: lists, pages, child
 * records, assignment and notices. Project viewers read; members change; managers manage people.
 */
class ProjectRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
    }

    /** @return array{boss: User, pm: User, editor: User, other: User, viewer: User, project: Project} */
    private function business(): array
    {
        $boss = User::factory()->withPersonalTeam()->create(['name' => 'Bongi Boss']);
        $team = $boss->currentTeam;
        $make = function (string $name, string $role) use ($team) {
            $u = User::factory()->create(['name' => $name, 'current_team_id' => $team->id]);
            $team->users()->attach($u, ['role' => $role]);

            return $u;
        };
        $pm = $make('Palesa PM', 'editor');
        $editor = $make('Sipho Editor', 'editor');
        $other = $make('Oscar Other', 'editor');
        $viewer = $make('Vusi Viewer', 'viewer');
        $project = Project::create(['team_id' => $team->id, 'owner_id' => $pm->id, 'name' => 'Restructure', 'status' => 'active', 'visibility' => 'private']);

        return ['boss' => $boss, 'pm' => $pm, 'editor' => $editor, 'other' => $other, 'viewer' => $viewer, 'project' => $project->fresh()];
    }

    public function test_a_private_project_is_invisible_outside_its_people(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'other' => $other, 'project' => $project] = $this->business();
        $open = Project::create(['team_id' => $project->team_id, 'owner_id' => $pm->id, 'name' => 'Canteen refit', 'status' => 'active']);

        $this->actingAs($other)->get(route('dashboard'))->assertSee('Canteen refit')->assertDontSee('Restructure');
        $this->actingAs($other)->get(route('projects.show', $project))->assertNotFound();
        $this->actingAs($other)->get(route('projects.risks', $project))->assertNotFound();
        $this->actingAs($other)->get(route('projects.show', $open))->assertOk();
        $this->assertFalse($other->can('view', $project));

        $this->actingAs($pm)->get(route('dashboard'))->assertSee('Restructure')->assertSee('Private');
        $this->actingAs($pm)->get(route('projects.show', $project))->assertOk()->assertSee('Private');
        // The business owner answers for everything in it.
        $this->actingAs($boss)->get(route('projects.show', $project))->assertOk();

        $this->actingAs($pm);
        $risk = app(Governance::class)->addRisk($project, $pm, ['title' => 'Union pushback', 'probability' => 3, 'impact' => 4]);
        $this->assertFalse($other->can('view', $risk));
        $this->actingAs($other)->get(route('projects.risks.show', [$project, $risk]))->assertNotFound();
    }

    public function test_roles_decide_who_reads_who_changes_and_who_manages(): void
    {
        ['pm' => $pm, 'editor' => $editor, 'other' => $other, 'viewer' => $viewer, 'project' => $project] = $this->business();
        $task = $project->tasks()->create(['title' => 'Draft the new structure', 'status' => 'todo', 'priority' => 'medium']);

        $panel = Livewire::actingAs($pm)->test(PeoplePanel::class, ['project' => $project]);
        $panel->set('newPerson', (string) $viewer->id)->set('newRole', 'member')->call('add')->assertHasErrors('member');
        $panel->set('newPerson', (string) $viewer->id)->set('newRole', 'viewer')->call('add')->assertHasNoErrors();
        $panel->set('newPerson', (string) $editor->id)->set('newRole', 'viewer')->call('add')->assertHasNoErrors();
        $this->assertSame('viewer', $project->roleOf($editor));

        $this->actingAs($editor)->get(route('projects.show', $project))->assertOk();
        Livewire::actingAs($editor)->test(TaskPanel::class, ['project' => $project, 'task' => $task])->set('title', 'Changed')->call('save')->assertForbidden();

        Livewire::actingAs($pm)->test(PeoplePanel::class, ['project' => $project])->call('setRole', $editor->id, 'member')->assertHasNoErrors();
        Livewire::actingAs($editor)->test(TaskPanel::class, ['project' => $project, 'task' => $task->fresh()])->set('title', 'Draft the new structure, v2')->call('save')->assertHasNoErrors();
        $this->assertSame('Draft the new structure, v2', $task->fresh()->title);

        Livewire::actingAs($editor)->test(PeoplePanel::class, ['project' => $project])->set('newPerson', (string) $other->id)->call('add')->assertForbidden();
        Livewire::actingAs($pm)->test(PeoplePanel::class, ['project' => $project])->call('setRole', $editor->id, 'manager');
        Livewire::actingAs($editor)->test(PeoplePanel::class, ['project' => $project])->set('newPerson', (string) $other->id)->set('newRole', 'member')->call('add')->assertHasNoErrors();
        $this->assertSame('member', $project->roleOf($other));
        Livewire::actingAs($editor)->test(PeoplePanel::class, ['project' => $project])->set('visibility', 'team')->call('setVisibility')->assertForbidden();

        Livewire::actingAs($pm)->test(PeoplePanel::class, ['project' => $project])->call('remove', $other->id);
        $this->assertNull($project->roleOf($other));
        $this->actingAs($other)->get(route('projects.show', $project))->assertNotFound();
        $this->actingAs($pm)->get(route('projects.show', $project))->assertSee('took Oscar Other off the project');
    }

    public function test_work_only_goes_to_people_who_can_see_the_project(): void
    {
        ['pm' => $pm, 'editor' => $editor, 'other' => $other, 'project' => $project] = $this->business();
        $task = $project->tasks()->create(['title' => 'Consult the staff', 'status' => 'todo', 'priority' => 'medium']);
        $this->assertSame(['Bongi Boss', 'Palesa PM'], $project->people()->pluck('name')->all());

        $this->actingAs($pm);
        try {
            app(ProjectWork::class)->updateTask($task, ['assignee_id' => $other->id], $pm);
            $this->fail('work went to someone who cannot see the project');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assignee_id', $e->errors());
        }
        Livewire::actingAs($pm)->test(ProjectBoard::class, ['project' => $project])->call('assignTask', $task->id, $other->id)->assertForbidden();

        app(ProjectPeople::class)->add($project, $pm, $other->id, 'member');
        app(ProjectWork::class)->updateTask($task->fresh(), ['assignee_id' => $other->id], $pm);
        $this->assertSame($other->id, $task->fresh()->assignee_id);
    }

    public function test_going_private_never_hides_open_work_from_its_assignee(): void
    {
        ['pm' => $pm, 'editor' => $editor, 'project' => $project] = $this->business();
        $project->forceFill(['visibility' => 'team'])->save();
        $project->tasks()->create(['title' => 'Interview the shift leads', 'status' => 'todo', 'priority' => 'medium', 'assignee_id' => $editor->id]);

        $panel = Livewire::actingAs($pm)->test(PeoplePanel::class, ['project' => $project->fresh()]);
        $panel->set('visibility', 'private')->call('setVisibility')->assertHasErrors('visibility');
        $this->assertSame('team', $project->fresh()->visibility);
        $this->assertSame('team', $panel->get('visibility'));

        $this->actingAs($pm);
        app(ProjectPeople::class)->add($project, $pm, $editor->id, 'member');
        Livewire::actingAs($pm)->test(PeoplePanel::class, ['project' => $project->fresh()])->set('visibility', 'private')->call('setVisibility')->assertHasNoErrors();
        $this->assertTrue($project->fresh()->isPrivate());
        $this->assertSame(['from' => 'team', 'to' => 'private'], Activity::query()->where('action', 'project.visibility_changed')->sole()->changes['visibility']);
    }

    public function test_a_new_project_can_start_private(): void
    {
        ['other' => $other] = $this->business();
        Livewire::actingAs($other)->test(CreateProject::class)->set('name', 'Pay review')->set('visibility', 'private')->call('save');
        $p = Project::withoutTeamScope(fn () => Project::query()->where('name', 'Pay review')->sole());
        $this->assertSame(['private', $other->id], [$p->visibility, $p->owner_id]);
        $this->actingAs($other)->get(route('projects.show', $p))->assertOk();
    }
}
