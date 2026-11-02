<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A group of a business's projects with an owner and a strategic objective: a portfolio, a
 * programme, a department, a business unit or a strategic initiative. Written through
 * App\Services\Portfolios; who may see each project inside it still follows the project.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $kind
 * @property string|null $objective
 * @property int|null $owner_id
 * @property-read User|null $owner
 * @property-read Team $team
 */
class Portfolio extends Model
{
    public const KINDS = ['portfolio' => 'Portfolio', 'programme' => 'Programme', 'department' => 'Department', 'unit' => 'Business unit', 'initiative' => 'Strategic initiative'];

    protected $fillable = ['name', 'kind', 'objective', 'owner_id'];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsToMany<Project, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'portfolio_project');
    }

    /** Edit it and choose its projects: its owner, or the business owner and admins. */
    public function manageableBy(User $user): bool
    {
        return $user->belongsToTeam($this->team) && ($user->id === $this->owner_id || $user->ownsTeam($this->team) || $user->hasTeamPermission($this->team, 'delete'));
    }
}
