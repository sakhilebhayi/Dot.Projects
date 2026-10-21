<?php

namespace Tests\Feature;

use App\Livewire\Projects\CreateProject;
use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Projects\ProjectComments;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectTask;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Laravel\Jetstream\Http\Livewire\UpdateTeamNameForm;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The read-only "viewer" role: its definition, and proof that every write path
 * in the app refuses it (403) while editors and admins keep working and a
 * viewer can still read every page.
 */
class ViewerRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    private Project $project;

    private ProjectTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->team = $this->owner->currentTeam;
        $this->project = Project::create([
            'team_id' => $this->team->id,
            'owner_id' => $this->owner->id,
            'name' => 'Shared Project',
            'status' => 'active',
        ]);
        $milestone = Milestone::create(['project_id' => $this->project->id, 'title' => 'M1', 'sort_order' => 0]);
        $this->task = $this->project->tasks()->create(['title' => 'A task', 'status' => 'backlog', 'milestone_id' => $milestone->id]);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->team->users()->attach($user, ['role' => $role]);
        $user->forceFill(['current_team_id' => $this->team->id])->save();

        return $user->fresh();
    }

    /** @return array<string, array{string}> */
    public static function writers(): array
    {
        return ['editor' => ['editor'], 'admin' => ['admin']];
    }

    // --- the role itself ---------------------------------------------------

    public function test_the_viewer_role_is_defined_read_only_and_the_others_are_unchanged(): void
    {
        $viewer = Jetstream::findRole('viewer');

        $this->assertNotNull($viewer);
        $this->assertSame('Viewer', $viewer->name);
        $this->assertSame(['read'], $viewer->permissions);
        $this->assertSame(['create', 'read', 'update', 'delete'], Jetstream::findRole('admin')->permissions);
        $this->assertSame(['read', 'create', 'update'], Jetstream::findRole('editor')->permissions);
        $this->assertContains('viewer', collect(Jetstream::$roles)->pluck('key')->all());
    }

    public function test_team_permissions_per_role(): void
    {
        $viewer = $this->member('viewer');
        $editor = $this->member('editor');
        $admin = $this->member('admin');

        $this->assertTrue($viewer->hasTeamPermission($this->team, 'read'));
        foreach (['create', 'update', 'delete'] as $permission) {
            $this->assertFalse($viewer->hasTeamPermission($this->team, $permission), "viewer must not have {$permission}");
        }

        $this->assertTrue($editor->hasTeamPermission($this->team, 'create'));
        $this->assertTrue($editor->hasTeamPermission($this->team, 'update'));
        $this->assertFalse($editor->hasTeamPermission($this->team, 'delete'));

        foreach (['read', 'create', 'update', 'delete'] as $permission) {
            $this->assertTrue($admin->hasTeamPermission($this->team, $permission));
            $this->assertTrue($this->owner->hasTeamPermission($this->team, $permission));
        }
    }

    public function test_a_membership_with_an_unknown_role_cannot_write(): void
    {
        $odd = $this->member('something-else');

        $this->assertFalse($odd->can('update', $this->project));
        $this->assertFalse($odd->can('create', Project::class));
    }

    // --- reads still work ----------------------------------------------------

    public function test_a_viewer_can_read_every_page(): void
    {
        $viewer = $this->member('viewer');

        $this->actingAs($viewer)->get(route('dashboard'))->assertOk()->assertSee('Shared Project');
        $this->actingAs($viewer)->get(route('projects.show', $this->project))->assertOk()->assertSee('A task');
        $this->actingAs($viewer)->get(route('notifications.index'))->assertOk();
        $this->actingAs($viewer)->get(route('teams.show', $this->team))->assertOk();
        $this->actingAs($viewer)->get(route('profile.show'))->assertOk();
    }

    public function test_a_viewer_does_not_see_write_controls_but_an_editor_does(): void
    {
        $viewer = $this->member('viewer');
        $editor = $this->member('editor');

        $this->actingAs($viewer)->get(route('dashboard'))->assertDontSee('New Project');
        $this->actingAs($viewer)->get(route('projects.show', $this->project))
            ->assertDontSee('Add Task')
            ->assertDontSee('draggable="true"', false)
            ->assertDontSee('Post Comment')
            ->assertSee('read-only access');

        $this->actingAs($editor)->get(route('dashboard'))->assertSee('New Project');
        $this->actingAs($editor)->get(route('projects.show', $this->project))
            ->assertSee('Add Task')
            ->assertSee('draggable="true"', false)
            ->assertSee('Post Comment');
    }

    // --- family: creating projects ------------------------------------------

    public function test_the_new_project_page_is_forbidden_to_a_viewer(): void
    {
        $this->actingAs($this->member('viewer'))->get(route('projects.create'))->assertForbidden();
    }

    #[DataProvider('writers')]
    public function test_the_new_project_page_is_open_to_editors_and_admins(string $role): void
    {
        $this->actingAs($this->member($role))->get(route('projects.create'))->assertOk();
    }

    public function test_a_viewer_cannot_create_a_project_or_generate_a_plan(): void
    {
        $viewer = $this->member('viewer');
        $before = Project::withoutGlobalScopes()->count();

        Livewire::actingAs($viewer)->test(CreateProject::class)
            ->set('name', 'Sneaky')->call('save')->assertForbidden();
        Livewire::actingAs($viewer)->test(CreateProject::class)
            ->set('name', 'Sneaky AI')->call('generatePlan')->assertForbidden();

        $this->assertSame($before, Project::withoutGlobalScopes()->count());
    }

    #[DataProvider('writers')]
    public function test_editors_and_admins_can_still_create_a_project(string $role): void
    {
        Livewire::actingAs($this->member($role))->test(CreateProject::class)
            ->set('name', "Made by {$role}")->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('projects', ['name' => "Made by {$role}", 'team_id' => $this->team->id]);
    }

    // --- family: the task board ---------------------------------------------

    public function test_a_viewer_cannot_move_or_assign_a_task(): void
    {
        $viewer = $this->member('viewer');

        Livewire::actingAs($viewer)->test(ProjectBoard::class, ['project' => $this->project])
            ->call('moveTask', $this->task->id, 'done')->assertForbidden();
        Livewire::actingAs($viewer)->test(ProjectBoard::class, ['project' => $this->project])
            ->call('assignTask', $this->task->id, $viewer->id)->assertForbidden();

        $this->assertSame('backlog', $this->task->fresh()->status);
        $this->assertNull($this->task->fresh()->assignee_id);
    }

    #[DataProvider('writers')]
    public function test_editors_and_admins_can_still_move_and_assign_a_task(string $role): void
    {
        $user = $this->member($role);

        Livewire::actingAs($user)->test(ProjectBoard::class, ['project' => $this->project])
            ->call('moveTask', $this->task->id, 'in_progress')
            ->call('assignTask', $this->task->id, $user->id)
            ->assertHasNoErrors();

        $this->assertSame('in_progress', $this->task->fresh()->status);
        $this->assertSame($user->id, $this->task->fresh()->assignee_id);
    }

    public function test_a_viewer_who_still_owns_a_project_cannot_confirm_or_reject_its_closure(): void
    {
        // Made a viewer after creating the project: ownership alone must not allow a write.
        $viewer = $this->member('viewer');
        $this->project->forceFill(['owner_id' => $viewer->id, 'status' => 'pending_closure_approval'])->save();

        Livewire::actingAs($viewer)->test(ProjectBoard::class, ['project' => $this->project])
            ->call('confirmProjectClosure')->assertForbidden();
        Livewire::actingAs($viewer)->test(ProjectBoard::class, ['project' => $this->project])
            ->call('promptRejectClosure')->assertForbidden();
        Livewire::actingAs($viewer)->test(ProjectBoard::class, ['project' => $this->project])
            ->call('confirmRejectClosure')->assertForbidden();

        $this->assertSame('pending_closure_approval', $this->project->fresh()->status);
    }

    public function test_an_editor_who_owns_a_project_can_still_confirm_its_closure(): void
    {
        $editor = $this->member('editor');
        $this->project->forceFill(['owner_id' => $editor->id, 'status' => 'pending_closure_approval'])->save();

        Livewire::actingAs($editor)->test(ProjectBoard::class, ['project' => $this->project])
            ->call('confirmProjectClosure');

        $this->assertSame('completed', $this->project->fresh()->status);
    }

    // --- family: comments ----------------------------------------------------

    public function test_a_viewer_cannot_post_a_comment_but_can_read_them(): void
    {
        ProjectComment::create([
            'commentable_type' => Project::class, 'commentable_id' => $this->project->id,
            'user_id' => $this->owner->id, 'body' => 'An existing remark',
        ]);
        $viewer = $this->member('viewer');

        Livewire::actingAs($viewer)->test(ProjectComments::class, ['project' => $this->project])
            ->assertSee('An existing remark')
            ->set('body', 'Hello')->call('addComment')->assertForbidden();

        $this->assertDatabaseMissing('project_comments', ['body' => 'Hello']);
    }

    #[DataProvider('writers')]
    public function test_editors_and_admins_can_still_post_a_comment(string $role): void
    {
        Livewire::actingAs($this->member($role))->test(ProjectComments::class, ['project' => $this->project])
            ->set('body', "From {$role}")->call('addComment')->assertHasNoErrors();

        $this->assertDatabaseHas('project_comments', ['body' => "From {$role}"]);
    }

    // --- family: policies for write paths that have no route yet ----------------

    public function test_policies_refuse_a_viewer_every_update_and_delete_but_allow_viewing(): void
    {
        $viewer = $this->member('viewer');
        $milestone = $this->task->milestone;
        $comment = ProjectComment::create([
            'commentable_type' => Project::class, 'commentable_id' => $this->project->id,
            'user_id' => $viewer->id, 'body' => 'Written before being demoted',
        ]);

        foreach ([$this->project, $milestone, $this->task, $comment] as $model) {
            $this->assertTrue($viewer->can('view', $model), 'viewer reads '.class_basename($model));
            $this->assertFalse($viewer->can('update', $model), 'viewer updates '.class_basename($model));
            $this->assertFalse($viewer->can('delete', $model), 'viewer deletes '.class_basename($model));
        }

        $this->assertFalse($viewer->can('comment', $this->project));
        $this->assertFalse($viewer->can('create', Project::class));
    }

    #[DataProvider('writers')]
    public function test_policies_still_allow_editors_and_admins_to_write(string $role): void
    {
        $user = $this->member($role);
        $milestone = $this->task->milestone;
        $comment = ProjectComment::create([
            'commentable_type' => Project::class, 'commentable_id' => $this->project->id,
            'user_id' => $user->id, 'body' => 'Mine',
        ]);

        foreach ([$this->project, $milestone, $this->task, $comment] as $model) {
            $this->assertTrue($user->can('update', $model));
            $this->assertTrue($user->can('delete', $model));
        }

        $this->assertTrue($user->can('comment', $this->project));
        $this->assertTrue($user->can('create', Project::class));
    }

    // --- family: team and member management --------------------------------------

    public function test_a_viewer_cannot_manage_the_team_or_its_members(): void
    {
        Mail::fake();
        $viewer = $this->member('viewer');
        $editor = $this->member('editor');
        $this->actingAs($viewer);

        Livewire::test(TeamMemberManager::class, ['team' => $this->team])
            ->set('addTeamMemberForm', ['email' => 'new@example.com', 'role' => 'editor'])
            ->call('addTeamMember')->assertForbidden();
        $this->assertCount(0, $this->team->fresh()->teamInvitations);

        Livewire::test(TeamMemberManager::class, ['team' => $this->team])
            ->set('managingRoleFor', $editor)->set('currentRole', 'viewer')
            ->call('updateRole')->assertForbidden();
        $this->assertTrue($editor->fresh()->hasTeamRole($this->team->fresh(), 'editor'));

        Livewire::test(TeamMemberManager::class, ['team' => $this->team])
            ->set('teamMemberIdBeingRemoved', $editor->id)
            ->call('removeTeamMember')->assertForbidden();
        $this->assertTrue($editor->fresh()->belongsToTeam($this->team->fresh()));

        Livewire::test(UpdateTeamNameForm::class, ['team' => $this->team])
            ->set('state', ['name' => 'Renamed by viewer'])
            ->call('updateTeamName')->assertForbidden();
        $this->assertNotSame('Renamed by viewer', $this->team->fresh()->name);

        $this->assertFalse($viewer->can('delete', $this->team));
        $this->assertFalse($viewer->can('update', $this->team));
    }

    public function test_a_viewer_cannot_cancel_a_pending_invitation_through_the_livewire_endpoint(): void
    {
        $invitation = $this->team->teamInvitations()->create(['email' => 'pending@example.com', 'role' => 'editor']);
        $viewer = $this->member('viewer');
        $this->actingAs($viewer);

        // Resolved by Jetstream's alias, so this proves the app's guarded component is the one served.
        Livewire::test('teams.team-member-manager', ['team' => $this->team])
            ->call('cancelTeamInvitation', $invitation->id)->assertForbidden();
        Livewire::test('teams.team-member-manager', ['team' => $this->team])
            ->call('manageRole', $this->owner->id)->assertForbidden();

        $this->assertCount(1, $this->team->fresh()->teamInvitations);

        $this->actingAs($this->owner);
        Livewire::test('teams.team-member-manager', ['team' => $this->team])
            ->call('cancelTeamInvitation', $invitation->id);
        $this->assertCount(0, $this->team->fresh()->teamInvitations);
    }

    public function test_the_role_picker_offers_viewer_and_an_owner_can_grant_it(): void
    {
        $editor = $this->member('editor');
        $this->actingAs($this->owner);

        $component = Livewire::test(TeamMemberManager::class, ['team' => $this->team]);
        $this->assertContains('viewer', collect($component->get('roles') ?? Jetstream::$roles)->pluck('key')->all());
        $component->assertSee('Viewer');

        $component->set('managingRoleFor', $editor)->set('currentRole', 'viewer')->call('updateRole');
        $this->assertTrue($editor->fresh()->hasTeamRole($this->team->fresh(), 'viewer'));
    }

    public function test_a_viewer_can_still_leave_the_team(): void
    {
        $viewer = $this->member('viewer');
        $this->actingAs($viewer);

        Livewire::test(TeamMemberManager::class, ['team' => $this->team])->call('leaveTeam');

        $this->assertFalse($viewer->fresh()->belongsToTeam($this->team->fresh()));
    }
}
