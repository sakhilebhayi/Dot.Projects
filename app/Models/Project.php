<?php

namespace App\Models;

use App\Events\ProjectClosed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Project extends Model
{
    protected $fillable = [
        'team_id', 'owner_id', 'name', 'description', 'status', 'start_date', 'due_date',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date'   => 'date',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('sort_order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(ProjectComment::class, 'commentable');
    }

    public function aiPlanLogs(): HasMany
    {
        return $this->hasMany(AiPlanLog::class);
    }

    public function completionPercentage(): int
    {
        $total = $this->tasks()->count();
        if ($total === 0) {
            return 0;
        }
        $done = $this->tasks()->where('status', 'done')->count();
        return (int) round(($done / $total) * 100);
    }

    /**
     * Close this project (status -> "completed") once it has at least one
     * milestone and every milestone is "completed". A no-op if the project
     * has no milestones yet, is already completed, or is "on_hold" (an
     * on-hold project isn't auto-reopened or auto-closed by task activity —
     * that status change stays a deliberate, manual one).
     *
     * Dispatches ProjectClosed, wiring the `delivery.project.closed` event
     * contract described in wiki.md §5 to the actual status transition.
     *
     * Called from ProjectBoard::moveTask() after Milestone::syncStatusFromTasks()
     * so a task move that finishes the last milestone can also close the project
     * in the same request.
     */
    public function closeIfAllMilestonesCompleted(): bool
    {
        if (in_array($this->status, ['completed', 'on_hold'], true)) {
            return false;
        }

        $milestoneStatuses = $this->milestones()->pluck('status');

        if ($milestoneStatuses->isEmpty() || $milestoneStatuses->contains(fn ($status) => $status !== 'completed')) {
            return false;
        }

        $this->update(['status' => 'completed']);

        ProjectClosed::dispatch($this);

        return true;
    }
}
