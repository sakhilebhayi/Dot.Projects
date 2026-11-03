<?php

namespace App\Models;

use App\Models\Concerns\BumpsProjectFacts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something that has happened and needs action: its severity, who owns it, the root cause, the
 * corrective action and, once resolved, how. May come from a risk that occurred. Written through
 * App\Services\Governance so every change lands in the activity log.
 *
 * @property int $id
 * @property int $project_id
 * @property int $number
 * @property string $title
 * @property string|null $description
 * @property string $category
 * @property string $severity
 * @property string $priority
 * @property int|null $owner_id
 * @property string $status
 * @property string|null $root_cause
 * @property string|null $corrective_action
 * @property Carbon|null $target_date
 * @property string|null $resolution
 * @property Carbon|null $resolved_at
 * @property int|null $risk_id
 * @property Carbon|null $escalated_at
 * @property int|null $escalated_by
 * @property string|null $escalation_note
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read Project $project
 * @property-read User|null $owner
 * @property-read User|null $escalator
 * @property-read ProjectRisk|null $risk
 */
class ProjectIssue extends Model
{
    use BumpsProjectFacts;

    public const STATUSES = ['open' => 'Open', 'investigating' => 'Being worked on', 'resolved' => 'Resolved', 'closed' => 'Closed'];

    public const OPEN = ['open', 'investigating'];

    public const SEVERITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    protected $guarded = ['id', 'project_id', 'number', 'created_by'];

    protected $casts = [
        'target_date' => 'date',
        'resolved_at' => 'datetime',
        'escalated_at' => 'datetime',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function escalator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    /** @return BelongsTo<ProjectRisk, $this> */
    public function risk(): BelongsTo
    {
        return $this->belongsTo(ProjectRisk::class, 'risk_id');
    }

    public function ref(): string
    {
        return 'I-'.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function late(?Carbon $today = null): bool
    {
        return $this->isOpen() && $this->target_date !== null && $this->target_date->lt($today ?? Carbon::today());
    }

    /** Severity first, then priority: the order the log is read in. */
    public function weight(): int
    {
        $s = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1][$this->severity] ?? 0;
        $p = ['urgent' => 4, 'high' => 3, 'medium' => 2, 'low' => 1][$this->priority] ?? 0;

        return $s * 10 + $p;
    }
}
