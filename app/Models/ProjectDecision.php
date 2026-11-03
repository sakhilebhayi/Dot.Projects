<?php

namespace App\Models;

use App\Models\Concerns\BumpsProjectFacts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One entry in the decision register: the question, its context, the options weighed, what was
 * decided, by whom and when. A proposed decision has an owner and a date it is needed by. Written
 * through App\Services\ChangeControl.
 *
 * @property int $id
 * @property int $project_id
 * @property int $number
 * @property string $title
 * @property string|null $context
 * @property string|null $options
 * @property string|null $outcome
 * @property string $status
 * @property int|null $owner_id
 * @property Carbon|null $decide_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_on
 * @property int|null $superseded_by
 * @property int|null $meeting_id
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read Project $project
 * @property-read User|null $owner
 * @property-read User|null $decider
 * @property-read ProjectDecision|null $successor
 * @property-read ProjectChange|null $change
 */
class ProjectDecision extends Model
{
    use BumpsProjectFacts;

    public const STATUSES = ['proposed' => 'Needs deciding', 'decided' => 'Decided', 'superseded' => 'Superseded'];

    protected $fillable = ['title', 'context', 'options', 'outcome', 'owner_id', 'decide_by', 'meeting_id'];

    protected $casts = ['decide_by' => 'date', 'decided_on' => 'date'];

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

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<ProjectDecision, $this> */
    public function successor(): BelongsTo
    {
        return $this->belongsTo(ProjectDecision::class, 'superseded_by');
    }

    /** The change request this decision approved or rejected, if any. @return HasOne<ProjectChange, $this> */
    public function change(): HasOne
    {
        return $this->hasOne(ProjectChange::class, 'decision_id');
    }

    /** @return BelongsTo<ProjectMeeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(ProjectMeeting::class, 'meeting_id');
    }

    public function ref(): string
    {
        return 'D-'.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT);
    }

    public function overdue(?Carbon $today = null): bool
    {
        return $this->status === 'proposed' && $this->decide_by !== null && $this->decide_by->lt($today ?? Carbon::today());
    }
}
