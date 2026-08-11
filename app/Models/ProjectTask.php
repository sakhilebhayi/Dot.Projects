<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProjectTask extends Model
{
    protected $fillable = [
        'project_id', 'milestone_id', 'assignee_id',
        'title', 'description', 'status', 'priority', 'due_date', 'sort_order',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(ProjectComment::class, 'commentable');
    }

    /** Tasks that must be "done" before this one can be marked "done". */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            ProjectTask::class,
            'task_dependencies',
            'task_id',
            'depends_on_task_id'
        )->withTimestamps();
    }

    /** Tasks that are waiting on this one. */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            ProjectTask::class,
            'task_dependencies',
            'depends_on_task_id',
            'task_id'
        )->withTimestamps();
    }

    /** True while any dependency is not yet "done". */
    public function isBlocked(): bool
    {
        return $this->dependencies()->where('status', '!=', 'done')->exists();
    }

    public function timeLogs(): HasMany
    {
        return $this->hasMany(TimeLog::class);
    }

    public function totalMinutesLogged(): int
    {
        return (int) $this->timeLogs()->sum('minutes');
    }
}
