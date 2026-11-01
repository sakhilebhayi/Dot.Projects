<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property Carbon|null $due_date
 * @property int $sort_order
 * @property-read Project $project
 * @property-read Milestone|null $milestone
 * @property-read User|null $assignee
 */
class ProjectTask extends Model
{
    protected $fillable = [
        'project_id', 'milestone_id', 'assignee_id',
        'title', 'description', 'status', 'priority', 'due_date', 'sort_order',
    ];

    protected $casts = [
        'due_date' => 'date',
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

    /** @return MorphMany<ProjectComment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(ProjectComment::class, 'commentable');
    }
}
