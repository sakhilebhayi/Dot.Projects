<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Dot.Projects is Jetstream-teams-scoped. Project is the only model that
 * actually owns a team_id column (see migrations/2026_06_27_151620_create_projects_table.php
 * and wiki.md §3) — Milestone, ProjectTask, ProjectComment, and AiPlanLog are
 * reached only through project_id (or a polymorphic commentable), not a
 * column of their own, so this trait is applied to Project alone rather than
 * assumed onto every model in app/Models. Their existing Policy layer
 * (MilestonePolicy, ProjectTaskPolicy, ProjectCommentPolicy, AiPlanLogPolicy)
 * already resolves the owning team by walking the ->project->team relation,
 * and stays exactly as-is.
 *
 * This trait mirrors Dot.Finance's HasUserScope (commit 2f75bdb) and
 * Dot.Notify's HasTeamScope (commit e671436): a query against Project is
 * scoped to the authenticated user's current team by default, so a
 * forgotten where('team_id', ...) call in a future controller/Livewire
 * component can no longer leak another team's rows, because the model
 * itself never returns unscoped results while a user is authenticated with
 * a current team.
 *
 * It fails closed (since R-34 P1): with nobody signed in, no current team, or a current
 * team the user does not belong to, a query returns no rows instead of every team's
 * rows. Work with no user that genuinely spans teams says so explicitly with
 * `Project::withoutTeamScope(fn () => ...)`.
 */
trait HasTeamScope
{
    protected static function bootHasTeamScope(): void
    {
        static::addGlobalScope('team', function (Builder $builder): void {
            if (TeamScopeBypass::active()) {
                return;
            }

            $user = Auth::user();
            $team = $user?->currentTeam;

            if ($user && $team && $user->belongsToTeam($team)) {
                $builder->where($builder->getModel()->getTable().'.team_id', $team->getKey());

                return;
            }

            $builder->whereRaw('1 = 0');
        });
    }

    /** Run $callback with the team scope switched off, explicitly. */
    public static function withoutTeamScope(callable $callback): mixed
    {
        return TeamScopeBypass::run($callback);
    }
}
