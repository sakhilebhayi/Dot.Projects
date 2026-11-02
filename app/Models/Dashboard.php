<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person's arrangement of widgets, optionally shared with the business. Written through
 * App\Services\Dashboards; every widget is worked out for the reader (App\Services\DashboardWidgets).
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property string $name
 * @property bool $shared
 * @property-read User $user
 * @property-read Team $team
 * @property-read Collection<int, DashboardWidget> $widgets
 */
class Dashboard extends Model
{
    protected $fillable = ['name', 'shared'];

    protected $casts = ['shared' => 'boolean'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<DashboardWidget, $this> */
    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('position')->orderBy('id');
    }

    /** Look at it: its maker, or anyone in the business once it is shared. */
    public function readableBy(User $user): bool
    {
        return $user->belongsToTeam($this->team) && ($this->user_id === $user->id || $this->shared);
    }

    /** Arrange, rename, share or delete it: its maker, or the business owner. */
    public function manageableBy(User $user): bool
    {
        return $user->belongsToTeam($this->team) && ($this->user_id === $user->id || $user->ownsTeam($this->team));
    }
}
