<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A report kept under a name: its type, filters, columns and sort. Shared reports share settings,
 * never rows; App\Services\Reporting works the rows out for whoever opens it.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property string $name
 * @property string $type
 * @property array{filters?: array<string, mixed>, columns?: list<string>, sort?: string|null, desc?: bool} $config
 * @property bool $shared
 * @property-read User $user
 * @property-read Team $team
 */
class SavedReport extends Model
{
    protected $fillable = ['name', 'type', 'config', 'shared'];

    protected $casts = ['config' => 'array', 'shared' => 'boolean'];

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

    /** Open it: its maker, or anyone in the business once it is shared. */
    public function readableBy(User $user): bool
    {
        return $user->belongsToTeam($this->team) && ($this->user_id === $user->id || $this->shared);
    }

    /** Rename, change, share or delete it: its maker, or the business owner. */
    public function manageableBy(User $user): bool
    {
        return $user->belongsToTeam($this->team) && ($this->user_id === $user->id || $user->ownsTeam($this->team));
    }
}
