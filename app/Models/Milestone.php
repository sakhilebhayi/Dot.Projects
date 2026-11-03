<?php

namespace App\Models;

use App\Events\MilestoneReached;
use App\Models\Concerns\BumpsProjectFacts;
use App\Services\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $title
 * @property string|null $description
 * @property Carbon|null $due_date
 * @property string $status
 * @property int $sort_order
 * @property-read Project $project
 */
class Milestone extends Model
{
    use BumpsProjectFacts;

    protected $fillable = [
        'project_id', 'title', 'description', 'due_date', 'status', 'sort_order',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<ProjectTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    /**
     * Recompute this milestone's status from its tasks' statuses and persist
     * it if it changed: "completed" once every task is done, "in_progress"
     * once at least one task has moved off the backlog, "pending" otherwise.
     * A milestone with no tasks yet is left "pending" — it has nothing to
     * derive progress from.
     *
     * Dispatches MilestoneReached when the status newly becomes "completed",
     * wiring the `delivery.milestone.reached` event contract described in
     * wiki.md §5 to the actual status transition instead of leaving it as
     * design intent only.
     *
     * Called from ProjectBoard::moveTask() whenever a task belonging to a
     * milestone changes status.
     */
    public function syncStatusFromTasks(): bool
    {
        $statuses = $this->tasks()->pluck('status');

        if ($statuses->isEmpty()) {
            return false;
        }

        $newStatus = match (true) {
            $statuses->every(fn ($status) => $status === 'done') => 'completed',
            $statuses->contains(fn ($status) => $status !== 'backlog') => 'in_progress',
            default => 'pending',
        };

        if ($newStatus === $this->status) {
            return false;
        }

        $wasCompleted = $this->status === 'completed';
        $from = $this->status;
        $this->update(['status' => $newStatus]);
        Activity::record('milestone.status_changed', $this, ['status' => ['from' => $from, 'to' => $newStatus]], Project::withoutTeamScope(fn () => $this->project()->first()));

        if ($newStatus === 'completed' && ! $wasCompleted) {
            MilestoneReached::dispatch($this);
        }

        return true;
    }
}
