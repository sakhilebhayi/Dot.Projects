<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $milestone_id
 * @property int|null $parent_id
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
 * @property-read Collection<int, ProjectTask> $predecessors
 * @property-read Collection<int, ProjectTask> $successors
 */
class ProjectTask extends Model
{
    protected $fillable = [
        'project_id', 'milestone_id', 'parent_id', 'assignee_id',
        'title', 'description', 'status', 'status_id', 'priority', 'start_date', 'due_date', 'estimate_minutes', 'completed_at', 'sort_order',
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

    /** @return BelongsTo<ProjectTask, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'parent_id');
    }

    /** @return HasMany<ProjectTask, $this> */
    public function subtasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    /** Tasks this one waits for (finish-to-start). @return BelongsToMany<ProjectTask, $this> */
    public function predecessors(): BelongsToMany
    {
        return $this->belongsToMany(ProjectTask::class, 'task_dependencies', 'successor_id', 'predecessor_id')->withTimestamps();
    }

    /** Tasks waiting for this one. @return BelongsToMany<ProjectTask, $this> */
    public function successors(): BelongsToMany
    {
        return $this->belongsToMany(ProjectTask::class, 'task_dependencies', 'predecessor_id', 'successor_id')->withTimestamps();
    }

    /** @return BelongsToMany<ProjectLabel, $this> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(ProjectLabel::class, 'project_label_task')->orderBy('name');
    }

    /**
     * Blocked: not done yet, and waiting on at least one task that is not done. Uses the loaded
     * predecessors when present, so lists can eager-load them.
     */
    public function isBlocked(): bool
    {
        if ($this->status === 'done') {
            return false;
        }

        return ($this->relationLoaded('predecessors') ? $this->predecessors : $this->predecessors()->get(['project_tasks.id', 'status']))
            ->contains(fn ($p) => $p->status !== 'done');
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

    /** @var array<int, Project|false> the task's project per viewer id, false where it is hidden */
    private array $seenBy = [];

    /**
     * The task's project when $viewer may see it, and null when they may not. Read without the
     * scopes, so the answer never depends on who happens to be signed in.
     */
    public function projectSeenBy(User $viewer): ?Project
    {
        if (! isset($this->seenBy[$viewer->id])) {
            $project = Project::withoutTeamScope(fn () => Project::query()->with('team')->find($this->project_id));
            $this->seenBy[$viewer->id] = $project !== null && $project->canBeSeenBy($viewer) ? $project : false;
        }

        return $this->seenBy[$viewer->id] ?: null;
    }

    /**
     * How the task is named to $viewer, looking from the project $hereId: by its title there; by
     * its title and project from another project they can see; and not named at all from a
     * project they cannot see.
     */
    public function nameFor(?User $viewer, int $hereId): string
    {
        if ((int) $this->project_id === $hereId) {
            return $this->title;
        }
        $project = $viewer === null ? null : $this->projectSeenBy($viewer);

        return $project === null ? 'a task in a project you cannot see' : $this->title.' (in '.$project->name.')';
    }
}
