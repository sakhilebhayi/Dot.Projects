<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's request to answer an approval step of a run. The first answer from any of the
 * people asked closes every request for that step.
 *
 * @property int $id
 * @property int $run_id
 * @property int $step
 * @property int $user_id
 * @property string $status pending, approved, rejected or expired
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $reason
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property-read AutomationRun $run
 * @property-read User $user
 * @property-read User|null $decider
 */
class AutomationApproval extends Model
{
    protected $fillable = ['run_id', 'step', 'user_id', 'status', 'expires_at'];

    protected $casts = ['decided_at' => 'datetime', 'expires_at' => 'datetime', 'step' => 'integer'];

    /** @return BelongsTo<AutomationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
