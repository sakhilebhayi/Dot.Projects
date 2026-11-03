<?php

namespace App\Models;

use App\Models\Concerns\BumpsProjectFacts;
use App\Services\RiskMatrix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Something that may happen to the project, rated on the project's risk matrix. Inherent rating
 * (probability x impact) before mitigation, residual rating after it. Written through
 * App\Services\Governance so every change lands in the activity log.
 *
 * @property int $id
 * @property int $project_id
 * @property int $number
 * @property string $title
 * @property string|null $description
 * @property string $category
 * @property int|null $owner_id
 * @property int $probability
 * @property int $impact
 * @property int|null $residual_probability
 * @property int|null $residual_impact
 * @property string|null $mitigation
 * @property string|null $contingency
 * @property string $status
 * @property Carbon|null $review_date
 * @property Carbon|null $escalated_at
 * @property int|null $escalated_by
 * @property string|null $escalation_note
 * @property Carbon|null $closed_at
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read Project $project
 * @property-read User|null $owner
 * @property-read User|null $escalator
 */
class ProjectRisk extends Model
{
    use BumpsProjectFacts;

    /** Open, mitigating and accepted risks are live: they stay on the matrix and need reviews. */
    public const STATUSES = ['open' => 'Open', 'mitigating' => 'Being mitigated', 'accepted' => 'Accepted', 'occurred' => 'Occurred', 'closed' => 'Closed'];

    public const LIVE = ['open', 'mitigating', 'accepted'];

    protected $guarded = ['id', 'project_id', 'number', 'created_by'];

    protected $casts = [
        'probability' => 'integer',
        'impact' => 'integer',
        'residual_probability' => 'integer',
        'residual_impact' => 'integer',
        'review_date' => 'date',
        'escalated_at' => 'datetime',
        'closed_at' => 'datetime',
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

    /** @return HasMany<ProjectIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(ProjectIssue::class, 'risk_id');
    }

    public function ref(): string
    {
        return 'R-'.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function score(): int
    {
        return $this->probability * $this->impact;
    }

    public function residualScore(): ?int
    {
        return $this->residual_probability && $this->residual_impact ? $this->residual_probability * $this->residual_impact : null;
    }

    /** The rating that counts now: residual once mitigation is planned and rated, else inherent. */
    public function currentScore(): int
    {
        return $this->residualScore() ?? $this->score();
    }

    public function reviewOverdue(?Carbon $today = null): bool
    {
        return $this->isLive() && $this->review_date !== null && $this->review_date->lt($today ?? Carbon::today());
    }

    public function band(RiskMatrix $matrix): string
    {
        return $matrix->band($this->currentScore());
    }
}
