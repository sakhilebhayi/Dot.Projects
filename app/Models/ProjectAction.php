<?php

namespace App\Models;

use App\Models\Concerns\BumpsProjectFacts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the action register: something a person agreed to do by a date, often in a meeting.
 * Written through App\Services\Meetings.
 *
 * @property int $id
 * @property int $project_id
 * @property int $number
 * @property string $title
 * @property int|null $owner_id
 * @property Carbon|null $due_date
 * @property string $status
 * @property int|null $meeting_id
 * @property Carbon|null $done_at
 * @property-read Project $project
 * @property-read User|null $owner
 * @property-read ProjectMeeting|null $meeting
 */
class ProjectAction extends Model
{
    use BumpsProjectFacts;

    protected $fillable = ['title', 'owner_id', 'due_date'];

    protected $casts = ['due_date' => 'date', 'done_at' => 'datetime'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<ProjectMeeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(ProjectMeeting::class, 'meeting_id');
    }

    public function ref(): string
    {
        return 'A-'.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT);
    }

    public function overdue(?Carbon $today = null): bool
    {
        return $this->status === 'open' && $this->due_date !== null && $this->due_date->lt($today ?? Carbon::today());
    }
}
