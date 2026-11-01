<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who is on a project, in what role, and who can see it. Members are people in the business;
 * roles are manager, member and viewer. On a team project the list says who runs it; on a private
 * project it is also who may see it at all.
 */
final class ProjectPeople
{
    public function add(Project $project, User $by, int $userId, string $role): void
    {
        $this->assertRole($role);
        $user = $project->team->allUsers()->firstWhere('id', $userId);
        if ($user === null) {
            throw ValidationException::withMessages(['member' => 'Choose someone in this business.']);
        }
        if ($user->id === $project->owner_id) {
            throw ValidationException::withMessages(['member' => $user->name.' owns the project and is always on it.']);
        }
        if ($project->roleOf($user) !== null) {
            throw ValidationException::withMessages(['member' => $user->name.' is already on the project.']);
        }
        if ($role !== 'viewer' && ! $user->hasTeamPermission($project->team, 'update')) {
            throw ValidationException::withMessages(['member' => $user->name.' can only read in this business, so add them as a viewer.']);
        }
        DB::table('project_members')->insert(['project_id' => $project->id, 'user_id' => $user->id, 'role' => $role, 'added_by' => $by->id, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
        Activity::record('member.added', $project, ['user_id' => $user->id, 'role' => $role]);
    }

    public function setRole(Project $project, int $userId, string $role): void
    {
        $this->assertRole($role);
        $user = User::query()->find($userId);
        $from = $user ? $project->roleOf($user) : null;
        if ($user === null || $from === null) {
            throw ValidationException::withMessages(['member' => 'That person is not on the project.']);
        }
        if ($role !== 'viewer' && ! $user->hasTeamPermission($project->team, 'update')) {
            throw ValidationException::withMessages(['member' => $user->name.' can only read in this business, so they stay a viewer.']);
        }
        if ($from === $role) {
            return;
        }
        DB::table('project_members')->where('project_id', $project->id)->where('user_id', $userId)->update(['role' => $role, 'updated_at' => Carbon::now()]);
        Activity::record('member.role_changed', $project, ['user_id' => $userId, 'role' => ['from' => $from, 'to' => $role]]);
    }

    public function remove(Project $project, int $userId): void
    {
        $removed = DB::table('project_members')->where('project_id', $project->id)->where('user_id', $userId)->delete();
        if ($removed) {
            Activity::record('member.removed', $project, ['user_id' => $userId]);
        }
    }

    /**
     * Opens the project to the business or makes it private. Making it private keeps work assigned
     * to someone who would lose sight of it from going dark: they are named and must be added
     * first, or the work reassigned.
     */
    public function setVisibility(Project $project, string $visibility): void
    {
        if (! in_array($visibility, ['team', 'private'], true)) {
            throw ValidationException::withMessages(['visibility' => 'Choose who can see the project.']);
        }
        if ($visibility === $project->visibility) {
            return;
        }
        if ($visibility === 'private') {
            $project->visibility = 'private';
            $outside = $project->tasks()->whereNotNull('assignee_id')->where('status', '!=', 'done')->with('assignee:id,name')->get()
                ->pluck('assignee')->filter()->unique('id')->reject(fn (User $u) => $project->canBeSeenBy($u))->pluck('name');
            $project->visibility = 'team';
            if ($outside->isNotEmpty()) {
                throw ValidationException::withMessages(['visibility' => $outside->join(', ', ' and ').' '.($outside->count() === 1 ? 'has' : 'have').' open work here but would no longer see the project. Add them first, or reassign the work.']);
            }
        }
        $from = $project->visibility;
        $project->forceFill(['visibility' => $visibility])->save();
        Activity::record('project.visibility_changed', $project, ['visibility' => ['from' => $from, 'to' => $visibility]]);
    }

    private function assertRole(string $role): void
    {
        if (! array_key_exists($role, Project::ROLES)) {
            throw ValidationException::withMessages(['member' => 'Choose manager, member or viewer.']);
        }
    }
}
