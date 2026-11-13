<?php

namespace App\Actions\Ecosystem;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies the companies InfoDot says a person belongs to, on every sign-in.
 *
 * A company here is a team linked to an InfoDot team by uuid. Rules:
 * - Only people InfoDot lists as owner or admin of a company create its team here;
 *   an ordinary member is attached once it exists, so a member can never become
 *   the local owner of someone else's company.
 * - Membership and role follow InfoDot (owner and admin become admin, editor stays
 *   editor, viewer stays viewer). Anything InfoDot sends that is not one of those
 *   becomes viewer, the least powerful role, so an unknown role can never write.
 *   The role of a membership that already exists is overwritten at every sign-in.
 * - Companies InfoDot no longer lists for the person are detached, so removing
 *   someone in InfoDot removes their access here at their next sign-in.
 * - The company InfoDot says is active becomes the person's current team.
 * - Personal teams are never linked or removed.
 * - R-50: InfoDot marks each company with `entitled`. Only a company marked exactly true is created or
 *   updated here and can become the current team. A company marked otherwise is left exactly as it is
 *   (no creation, no rename, no role change, no detaching: the person still belongs to it in InfoDot), and
 *   a person whose current team it was is moved to an entitled company, or to their personal team. A
 *   response without the key (an InfoDot from before R-50) is applied as it always was.
 */
class SyncTeamsFromInfoDot
{
    /**
     * @param  array<string, mixed>  $identity  the redeem response
     */
    public function __invoke(User $user, array $identity): void
    {
        $companies = collect($identity['teams'] ?? [])
            ->filter(fn ($t) => is_array($t) && filled($t['uuid'] ?? null) && ! ($t['personal'] ?? false))
            ->keyBy('uuid');
        $listed = $companies->filter(fn ($t) => self::admits($t));
        $notEntitled = $companies->keys()->diff($listed->keys())->map(fn ($uuid) => (string) $uuid)->all();

        DB::transaction(function () use ($user, $companies, $listed, $notEntitled, $identity) {
            foreach ($listed as $uuid => $info) {
                $this->apply($user, (string) $uuid, $info);
                $this->forgetCachedTeams($user);
            }

            // A company that is listed but not entitled is still the person's company: it is not revoked.
            $this->revoke($user, $companies->keys()->map(fn ($uuid) => (string) $uuid)->all());
            $this->forgetCachedTeams($user);

            $active = $identity['current_team_uuid'] ?? null;
            $team = $active && $listed->has($active) ? Team::where('infodot_team_uuid', $active)->first() : null;

            if ($team && $user->belongsToTeam($team)) {
                $user->switchTeam($team);

                return;
            }

            $this->leaveCompanyWithoutThePlatform($user, $listed->keys()->all(), $notEntitled);
        });
    }

    /**
     * Whether InfoDot lets this company use the platform. Exactly true admits; any other value refuses; a
     * missing key is an InfoDot from before R-50 and admits, as it always did.
     *
     * @param  array<string, mixed>  $team
     */
    public static function admits(array $team): bool
    {
        return ! array_key_exists('entitled', $team) || $team['entitled'] === true;
    }

    /**
     * When InfoDot marked the person's workspaces and not one may use this platform: the reason to show
     * (the active workspace's, else the first one given) and the company's name. Null when anyone is
     * admitted, or when InfoDot sent no marks at all.
     *
     * @param  array<string, mixed>  $identity
     * @return array{access: ?string, company: ?string}|null
     */
    public static function refusedOutright(array $identity): ?array
    {
        $teams = collect($identity['teams'] ?? [])->filter(fn ($t) => is_array($t))->values();

        if ($teams->isEmpty() || ! $teams->contains(fn ($t) => array_key_exists('entitled', $t)) || $teams->contains(fn ($t) => self::admits($t))) {
            return null;
        }

        $active = $identity['current_team_uuid'] ?? null;
        $shown = $teams->first(fn ($t) => $active !== null && ($t['uuid'] ?? null) === $active)
            ?? $teams->first(fn ($t) => is_string($t['access'] ?? null))
            ?? $teams->first();

        return [
            'access' => is_string($shown['access'] ?? null) ? $shown['access'] : null,
            'company' => ($shown['personal'] ?? false) || blank($shown['name'] ?? null) ? null : (string) $shown['name'],
        ];
    }

    /**
     * The person's current team here is a company InfoDot refused: move them to an entitled company, else
     * to their personal team. The refused company and everything in it stay as they are.
     *
     * @param  list<string>  $entitled
     * @param  list<string>  $notEntitled
     */
    private function leaveCompanyWithoutThePlatform(User $user, array $entitled, array $notEntitled): void
    {
        $current = $user->currentTeam;

        if (! $current instanceof Team || ! in_array($current->infodot_team_uuid, $notEntitled, true)) {
            return;
        }

        $teams = $user->allTeams();
        $next = $teams->first(fn ($team) => in_array($team->infodot_team_uuid, $entitled, true))
            ?? $teams->first(fn ($team) => $team->personal_team && $team->user_id === $user->id);

        if ($next instanceof Team) {
            $user->switchTeam($next);
        }
    }

    /** Membership checks read relations cached on the model; reload after changing them. */
    private function forgetCachedTeams(User $user): void
    {
        $user->unsetRelation('teams')->unsetRelation('ownedTeams')->unsetRelation('currentTeam');
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function apply(User $user, string $uuid, array $info): void
    {
        $role = (string) ($info['role'] ?? '');
        $team = Team::where('infodot_team_uuid', $uuid)->first();

        if (! $team) {
            if (! in_array($role, ['owner', 'admin'], true)) {
                return;
            }

            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => (string) ($info['name'] ?? 'Company'),
                'personal_team' => false,
                'infodot_team_uuid' => $uuid,
            ]);
        } elseif (filled($info['name'] ?? null) && $team->name !== $info['name']) {
            $team->forceFill(['name' => $info['name']])->save();
        }

        if ($team->user_id === $user->id) {
            return; // the local owner needs no membership row
        }

        $localRole = $this->localRole($role);

        if ($user->belongsToTeam($team)) {
            $team->users()->updateExistingPivot($user->id, ['role' => $localRole]);
        } else {
            $team->users()->attach($user->id, ['role' => $localRole]);
        }
    }

    /**
     * @param  list<string>  $keep
     */
    private function revoke(User $user, array $keep): void
    {
        $stale = $user->allTeams()->filter(fn ($team) => filled($team->infodot_team_uuid) && ! in_array($team->infodot_team_uuid, $keep, true));

        foreach ($stale as $team) {
            if ($team->user_id === $user->id) {
                // Cannot detach a team's owner; surface it instead of leaving it silent.
                Log::warning('InfoDot no longer lists a company this person owns locally.', ['team_id' => $team->id, 'user_id' => $user->id]);

                continue;
            }

            $team->users()->detach($user->id);

            if ($user->current_team_id === $team->id) {
                $user->forceFill(['current_team_id' => null])->save();
            }
        }
    }

    private function localRole(string $infodotRole): string
    {
        return match ($infodotRole) {
            'owner', 'admin' => 'admin',
            'editor' => 'editor',
            default => 'viewer',
        };
    }
}
