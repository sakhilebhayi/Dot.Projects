<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $milestone_id
 * @property int|null $assignee_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property string $priority
 * @property Carbon|null $start_date
 * @property Carbon|null $due_date
 * @property int|null $estimate_minutes
 * @property Carbon|null $completed_at
 * @property int $sort_order
 * @property-read Project $project
 * @property-read Milestone|null $milestone
 * @property-read User|null $assignee
 */
class ProjectTask extends Model
{
    protected $fillable = [
        'project_id', 'milestone_id', 'assignee_id',
        'title', 'description', 'status', 'priority', 'start_date', 'due_date', 'estimate_minutes', 'completed_at', 'sort_order',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'estimate_minutes' => 'integer',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Milestone, $this> */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return HasMany<ChecklistItem, $this> */
    public function checklist(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    /** The estimate as people say it: "3 h", "1 h 30 m", "45 m". */
    public function estimateLabel(): ?string
    {
        if ($this->estimate_minutes === null) {
            return null;
        }
        $h = intdiv($this->estimate_minutes, 60);
        $m = $this->estimate_minutes % 60;

        return trim(($h ? $h.' h ' : '').($m ? $m.' m' : '')) ?: '0 m';
    }

    /** @return MorphMany<ProjectComment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(ProjectComment::class, 'commentable');
    }
}
