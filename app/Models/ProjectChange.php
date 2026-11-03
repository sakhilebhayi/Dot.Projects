<?php

namespace App\Models;

use App\Models\Concerns\BumpsProjectFacts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A change request: what changes and why, its impact on time, money, scope, people and risk, and
 * the approval it went through. Written through App\Services\ChangeControl.
 *
 * @property int $id
 * @property int $project_id
 * @property int $number
 * @property string $title
 * @property string $kind
 * @property string|null $reason
 * @property string|null $description
 * @property int|null $schedule_days
 * @property int|null $cost_cents
 * @property string|null $scope_impact
 * @property string|null $resource_impact
 * @property string|null $risk_impact
 * @property string $status
 * @property int|null $requested_by
 * @property int|null $approver_id
 * @property Carbon|null $submitted_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property bool $self_approved
 * @property Carbon|null $implemented_at
 * @property int|null $baseline_id
 * @property int|null $decision_id
 * @property Carbon $created_at
 * @property-read Project $project
 * @property-read User|null $requester
 * @property-read User|null $approver
 * @property-read User|null $decider
 * @property-read Baseline|null $baseline
 * @property-read ProjectDecision|null $decision
 */
class ProjectChange extends Model
{
    use BumpsProjectFacts;

    public const KINDS = ['scope' => 'Scope', 'schedule' => 'Schedule', 'budget' => 'Budget', 'resources' => 'People and resources', 'quality' => 'Quality', 'other' => 'Other'];

    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn', 'implemented' => 'Implemented'];

    protected $fillable = ['title', 'kind', 'reason', 'description', 'schedule_days', 'cost_cents', 'scope_impact', 'resource_impact', 'risk_impact'];

    protected $casts = [
        'schedule_days' => 'integer',
        'cost_cents' => 'integer',
        'self_approved' => 'boolean',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'implemented_at' => 'datetime',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
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

    /** @return BelongsTo<Baseline, $this> */
    public function baseline(): BelongsTo
    {
        return $this->belongsTo(Baseline::class);
    }

    /** @return BelongsTo<ProjectDecision, $this> */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(ProjectDecision::class);
    }

    public function ref(): string
    {
        return 'CR-'.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT);
    }

    /** Approved or implemented: its impact is part of the plan. */
    public function accepted(): bool
    {
        return in_array($this->status, ['approved', 'implemented'], true);
    }
}
