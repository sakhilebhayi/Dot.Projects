<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A numbered snapshot of a project's dates. Read-only once taken.
 *
 * @property int $id
 * @property int $project_id
 * @property int $number
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read User|null $creator
 */
class Baseline extends Model
{
    protected $table = 'project_baselines';

    protected $fillable = ['project_id', 'number', 'note', 'created_by'];

    /** @return HasMany<BaselineItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BaselineItem::class, 'project_baseline_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
