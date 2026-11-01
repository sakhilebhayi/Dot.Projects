<?php

namespace App\Models;

use App\Events\ProjectClosed;
use App\Models\Concerns\HasTeamScope;
use App\Services\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $owner_id
 * @property string $name
 * @property string|null $description
 * @property string $status
 * @property Carbon|null $start_date
 * @property Carbon|null $due_date
 * @property Carbon|null $archived_at
 * @property array<string, mixed>|null $risk_matrix
 * @property int|null $archived_by
 * @property-read Team $team
 * @property-read User $owner
 */
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
        'archived_at' => 'datetime',
        'risk_matrix' => 'array',
    ];

    /**
     * The team a project belongs to, read without the team scope. Policies use it: they are the
     * access check themselves, so they must not depend on who happens to be signed in.
     */
    public static function owningTeam(?int $projectId): ?Team
    {
        if ($projectId === null) {
            return null;
        }

        return static::withoutTeamScope(fn () => static::query()->with('team')->find($projectId)?->team);
    }

    /**
     * Whether $user may change work inside the project $projectId: a member with the team's
     * "update" permission, and the project not archived. Read without the team scope, like
     * owningTeam(), because policies are the access check themselves.
     */
    public static function writableBy(User $user, ?int $projectId): bool
    {
        if ($projectId === null) {
            return false;
        }
        $project = static::withoutTeamScope(fn () => static::query()->with('team')->find($projectId));

        return $project !== null && ! $project->isArchived() && $user->hasTeamPermission($project->team, 'update');
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<Milestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('sort_order');
    }

    /** @return HasMany<ProjectTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    /** @return MorphMany<ProjectComment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(ProjectComment::class, 'commentable');
    }

    /** @return HasMany<ProjectLabel, $this> */
    public function labels(): HasMany
    {
        return $this->hasMany(ProjectLabel::class)->orderBy('name');
    }

    /** @return HasMany<Baseline, $this> */
    public function baselines(): HasMany
    {
        return $this->hasMany(Baseline::class)->orderByDesc('number');
    }

    /** @return HasMany<AiPlanLog, $this> */
    public function aiPlanLogs(): HasMany
    {
        return $this->hasMany(AiPlanLog::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
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
        Activity::record('project.closure_proposed', $this, ['status' => 'pending_closure_approval']);

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

        Activity::record('project.closed', $this, ['status' => 'completed']);
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
        Activity::record('project.closure_rejected', $this, ['status' => 'active']); // the reason is text, so it stays on the project only

        return true;
    }

    /** @return HasMany<ProjectRisk, $this> */
    public function risks(): HasMany
    {
        return $this->hasMany(ProjectRisk::class);
    }

    /** @return HasMany<ProjectIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(ProjectIssue::class);
    }

    /** @return HasMany<ProjectChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(ProjectChange::class);
    }

    /** @return HasMany<ProjectDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(ProjectDecision::class);
    }

    /** @return HasMany<ProjectGate, $this> */
    public function gates(): HasMany
    {
        return $this->hasMany(ProjectGate::class)->orderBy('number');
    }

    /** @return HasMany<ProjectMeeting, $this> */
    public function meetings(): HasMany
    {
        return $this->hasMany(ProjectMeeting::class);
    }

    /** @return HasMany<ProjectAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ProjectAction::class);
    }

    /** @return HasMany<ProjectReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(ProjectReport::class);
    }
}
