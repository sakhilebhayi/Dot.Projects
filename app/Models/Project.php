<?php

namespace App\Models;

use App\Events\ProjectClosed;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Project extends Model
{
    use HasTeamScope;

    protected $fillable = [
        'team_id', 'owner_id', 'name', 'description', 'status', 'start_date', 'due_date',
        'closure_reviewed_by', 'closure_reviewed_at', 'closure_rejected_reason',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
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

    /** Sum of every logged minute across every task in this project. */
    public function totalMinutesLogged(): int
    {
        return (int) TimeLog::whereIn('project_task_id', $this->tasks()->pluck('id'))->sum('minutes');
    }

    /**
     * Propose closing this project (status -> "pending_closure_approval")
     * once it has at least one milestone and every milestone is
     * "completed". A no-op if the project has no milestones yet, is
     * already completed, is "on_hold" (an on-hold project isn't
     * auto-reopened or auto-closed by task activity -- that status change
     * stays a deliberate, manual one), or already has a closure pending.
     *
     * Does NOT dispatch ProjectClosed -- that now only happens from
     * confirmClosure(), once the project's owner has actually confirmed.
     *
     * Called from ProjectBoard::moveTask() after Milestone::syncStatusFromTasks()
     * so a task move that finishes the last milestone can also propose
     * closing the project in the same request.
     */
    public function closeIfAllMilestonesCompleted(): bool
    {
        if (in_array($this->status, ['completed', 'on_hold', 'pending_closure_approval'], true)) {
            return false;
        }

        $milestoneStatuses = $this->milestones()->pluck('status');

        if ($milestoneStatuses->isEmpty() || $milestoneStatuses->contains(fn ($status) => $status !== 'completed')) {
            return false;
        }

        $this->update(['status' => 'pending_closure_approval']);

        return true;
    }

    /**
     * The project owner confirms a pending closure. This is the only
     * place ProjectClosed is dispatched. A no-op (returns false) unless
     * the project is actually pending_closure_approval -- callers are
     * responsible for authorizing the caller as the owner before calling
     * this (see ProjectPolicy::closeProject(), checked in
     * ProjectBoard::confirmProjectClosure()).
     */
    public function confirmClosure(): bool
    {
        if ($this->status !== 'pending_closure_approval') {
            return false;
        }

        $this->update([
            'status' => 'completed',
            'closure_reviewed_by' => $this->owner_id,
            'closure_reviewed_at' => now(),
        ]);

        ProjectClosed::dispatch($this);

        return true;
    }

    /**
     * The project owner rejects a pending closure. Not a terminal state --
     * the project returns to "active" so work can continue, and
     * closeIfAllMilestonesCompleted() can propose closure again later.
     * A no-op (returns false) unless the project is actually
     * pending_closure_approval.
     */
    public function rejectClosure(string $reason): bool
    {
        if ($this->status !== 'pending_closure_approval') {
            return false;
        }

        $this->update([
            'status' => 'active',
            'closure_rejected_reason' => $reason,
            'closure_reviewed_by' => $this->owner_id,
            'closure_reviewed_at' => now(),
        ]);

        return true;
    }
}
