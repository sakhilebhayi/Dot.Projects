<?php

namespace App\Http\Middleware;

use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Models\Team;
use App\Services\InfoDot\CompanyEntitlement;
use App\Support\EntitlementRefusal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * During a session: the signed-in area works only while the person's CURRENT company holds Dot.Projects in
 * InfoDot (R-50). InfoDot's answer is read for that one company and kept for ten minutes.
 *
 * - Held: carry on.
 * - Not held: a plain 403 page with InfoDot's reason; nothing of the company is shown or changed.
 * - Not known (any failure): carry on as before; CompanyEntitlement logs it.
 *
 * Only routes behind sign-in are checked, plus Livewire's own endpoints for a signed-in person (they carry
 * every action of the signed-in screens). Sign-in, sign-out, the handoff receiver, health checks, public
 * pages, the API and the company switcher are left alone. For a personal team, or a team with no InfoDot
 * company, InfoDot is not asked: what it said about the personal workspace at sign-in (kept in the session)
 * stands, and only an exact "not entitled" refuses. Nothing remembered (an older InfoDot, or a session from
 * before this release) carries on.
 */
class EnsureCompanyEntitled
{
    /** Signed-in routes that must keep working for a refused company: leaving, and switching company. */
    private const ALWAYS_OPEN = ['logout', 'current-team.update', 'ecosystem.auth'];

    public function __construct(private CompanyEntitlement $entitlement) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->entitlement->enabled() || ! $this->guards($request)) {
            return $next($request);
        }

        $user = $request->user();
        $team = $user?->currentTeam;

        // A personal team, or any team with no InfoDot company: InfoDot is never asked about it. What it said
        // about the personal workspace at sign-in stands; only an exact "no" refuses.
        if (! $team instanceof Team || $team->personal_team || blank($team->infodot_team_uuid)) {
            if ($user === null || ! $request->hasSession() || $request->session()->get(SyncTeamsFromInfoDot::PERSONAL_ENTITLED) !== false) {
                return $next($request);
            }

            return response()->view('entitlement.refused', [
                ...EntitlementRefusal::words('company_required'),
                'manageUrl' => $this->entitlement->manageUrl(),
                'otherCompanies' => $user->allTeams()->filter(fn ($other) => ! $other->personal_team && filled($other->infodot_team_uuid))->values(),
            ], 403);
        }

        $answer = $this->entitlement->for((string) $team->infodot_team_uuid);

        if ($answer === null || $answer['entitled']) {
            return $next($request);
        }

        return response()->view('entitlement.refused', [
            ...EntitlementRefusal::words($answer['status'], $team->name),
            'manageUrl' => $this->entitlement->manageUrl(),
            'otherCompanies' => $user->allTeams()->reject(fn ($other) => $other->getKey() === $team->getKey())->values(),
        ], 403);
    }

    private function guards(Request $request): bool
    {
        $route = $request->route();

        if ($route === null || $request->is('api/*') || in_array($route->getName(), self::ALWAYS_OPEN, true)) {
            return false;
        }

        if ($request->user() === null) {
            return false;
        }

        if (str_starts_with((string) $route->getName(), 'livewire.')) {
            return true;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:'))) {
                return true;
            }
        }

        return false;
    }
}
