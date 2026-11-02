<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A business's retention choices (App\Services\DataRetention).
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $activity_months null keeps the activity history for ever
 * @property int $notice_days
 * @property int $automation_days
 * @property int|null $updated_by
 * @property Carbon|null $last_run_at
 * @property array{activity?: int, notices?: int, automation_runs?: int}|null $last_removed
 * @property Carbon $updated_at
 */
class RetentionPolicy extends Model
{
    protected $fillable = ['activity_months', 'notice_days', 'automation_days'];

    protected $casts = ['last_run_at' => 'datetime', 'last_removed' => 'array', 'activity_months' => 'integer', 'notice_days' => 'integer', 'automation_days' => 'integer'];
}
