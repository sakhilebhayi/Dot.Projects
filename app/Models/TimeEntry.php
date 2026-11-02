<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Time a person logged on a project (and maybe a task): by hand, or with a timer, which is an
 * entry with running_since set and no minutes until it is stopped. Written through
 * App\Services\TimeTracking.
 *
 * @property int $id
 * @property int $team_id
 * @property int $project_id
 * @property int|null $task_id
 * @property int $user_id
 * @property Carbon $work_date
 * @property int|null $minutes
 * @property Carbon|null $running_since
 * @property string|null $note
 * @property bool $billable
 * @property-read Project $project
 * @property-read ProjectTask|null $task
 * @property-read User $user
 */
class TimeEntry extends Model
{
    protected $fillable = ['project_id', 'task_id', 'work_date', 'minutes', 'note', 'billable'];

    protected $casts = ['work_date' => 'date', 'minutes' => 'integer', 'running_since' => 'datetime', 'billable' => 'boolean'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function running(): bool
    {
        return $this->running_since !== null;
    }

    /** Minutes so far: stored once stopped, counted from the start while running. */
    public function elapsed(?Carbon $now = null): int
    {
        if ($this->running_since === null) {
            return (int) $this->minutes;
        }

        return max(1, (int) ceil($this->running_since->diffInSeconds($now ?? Carbon::now()) / 60));
    }
}
