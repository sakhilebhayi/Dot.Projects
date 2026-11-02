<?php

namespace App\Services;

use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Portfolios and programmes: created by the business owner or an admin, managed by them or the
 * portfolio's owner. Choosing projects only touches projects the person can see, so a private
 * project someone else put in a portfolio is never removed by someone who cannot see it.
 */
final class Portfolios
{
    /** @param array{name: string, kind: string, objective?: string|null, owner_id?: int|null} $data */
    public function create(Team $team, User $by, array $data): Portfolio
    {
        if (! Workload::oversees($by, $team)) {
            throw ValidationException::withMessages(['portfolio' => 'Only the business owner or an admin creates portfolios.']);
        }
        $p = new Portfolio($this->validate($team, $data));
        $p->forceFill(['team_id' => $team->id, 'created_by' => $by->id])->save();

        return $p;
    }

    /** @param array{name: string, kind: string, objective?: string|null, owner_id?: int|null} $data */
    public function update(Portfolio $portfolio, User $by, array $data): void
    {
        $this->assertManage($portfolio, $by);
        $portfolio->fill($this->validate($portfolio->team, $data))->save();
    }

    /** @param list<int> $projectIds the projects $by wants in it, among those $by can see */
    public function setProjects(Portfolio $portfolio, User $by, array $projectIds): void
    {
        $this->assertManage($portfolio, $by);
        $visible = Project::withoutTeamScope(fn () => Project::query()->with('team')->where('team_id', $portfolio->team_id)->get())
            ->filter(fn (Project $p) => $p->canBeSeenBy($by))->pluck('id')->map(fn ($id) => (int) $id);
        $wanted = collect($projectIds)->map(fn ($id) => (int) $id)->unique();
        if ($wanted->diff($visible)->isNotEmpty()) {
            throw ValidationException::withMessages(['projects' => 'Choose projects of this business that you can see.']);
        }
        DB::transaction(function () use ($portfolio, $visible, $wanted) {
            $portfolio->projects()->detach($visible->diff($wanted)->all());
            $portfolio->projects()->syncWithoutDetaching($wanted->all());
        });
    }

    public function delete(Portfolio $portfolio, User $by): void
    {
        if (! Workload::oversees($by, $portfolio->team)) {
            throw ValidationException::withMessages(['portfolio' => 'Only the business owner or an admin removes a portfolio.']);
        }
        $portfolio->delete();
    }

    private function assertManage(Portfolio $portfolio, User $by): void
    {
        if (! $portfolio->manageableBy($by)) {
            throw ValidationException::withMessages(['portfolio' => 'Only its owner, the business owner or an admin changes this portfolio.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, kind: string, objective: string|null, owner_id: int|null}
     */
    private function validate(Team $team, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            throw ValidationException::withMessages(['name' => 'Name it in up to 160 characters.']);
        }
        if (! array_key_exists((string) ($data['kind'] ?? ''), Portfolio::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Choose what kind of group it is.']);
        }
        $owner = $data['owner_id'] ?? null;
        if ($owner !== null && $team->allUsers()->firstWhere('id', (int) $owner) === null) {
            throw ValidationException::withMessages(['owner_id' => 'Choose someone in this business.']);
        }
        $objective = trim((string) ($data['objective'] ?? ''));
        if (mb_strlen($objective) > 2000) {
            throw ValidationException::withMessages(['objective' => 'Keep the objective under 2000 characters.']);
        }

        return ['name' => $name, 'kind' => (string) $data['kind'], 'objective' => $objective === '' ? null : $objective, 'owner_id' => $owner === null ? null : (int) $owner];
    }
}
