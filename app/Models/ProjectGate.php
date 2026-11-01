<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A stage gate: the point where the project may only go on once its checklist is met and a named
 * approver passes it (or passes it with conditions, or holds it). Written through App\Services\Gates.
 *
 * @property int $id
 * @property int $project_id
 * @property int $number
 * @property string $title
 * @property string|null $purpose
 * @property int|null $milestone_id
 * @property Carbon|null $due_date
 * @property int|null $approver_id
 * @property string $status
 * @property string|null $decision_note
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property int|null $decision_id
 * @property Carbon $created_at
 * @property-read Project $project
 * @property-read Milestone|null $milestone
 * @property-read User|null $approver
 * @property-read User|null $decider
 * @property-read ProjectDecision|null $decision
 * @property-read Collection<int, GateCheck> $checks
 */
class ProjectGate extends Model
{
    public const STATUSES = ['pending' => 'Not yet passed', 'passed' => 'Passed', 'conditional' => 'Passed with conditions', 'held' => 'Held'];

    protected $fillable = ['title', 'purpose', 'milestone_id', 'due_date', 'approver_id'];

    protected $casts = ['due_date' => 'date', 'decided_at' => 'datetime'];

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
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<ProjectDecision, $this> */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(ProjectDecision::class);
    }

    /** @return HasMany<GateCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(GateCheck::class)->orderBy('sort_order')->orderBy('id');
    }

    public function ref(): string
    {
        return 'G'.$this->number;
    }

    public function open(): bool
    {
        return in_array($this->status, ['pending', 'held'], true);
    }

    public function overdue(?Carbon $today = null): bool
    {
        return $this->open() && $this->due_date !== null && $this->due_date->lt($today ?? Carbon::today());
    }
}
