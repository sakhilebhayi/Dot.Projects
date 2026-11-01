<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the activity log: an action on a record, by whom, with the enumerated values that
 * changed. Written only through App\Services\Activity; never updated.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $project_id
 * @property int|null $user_id
 * @property string $action
 * @property string $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $changes
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class Activity extends Model
{
    use HasTeamScope;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
