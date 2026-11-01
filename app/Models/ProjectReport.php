<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A status report for a period: the manager's overall rating and words, and (once published) the
 * project's facts frozen as a snapshot. Written through App\Services\StatusReports.
 *
 * @property int $id
 * @property int $project_id
 * @property int $number
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property string|null $overall
 * @property string|null $summary
 * @property string|null $achievements
 * @property string|null $next_steps
 * @property string|null $help_needed
 * @property string $status
 * @property array<string, mixed>|null $snapshot
 * @property Carbon|null $published_at
 * @property int|null $published_by
 * @property int|null $created_by
 * @property-read Project $project
 * @property-read User|null $publisher
 */
class ProjectReport extends Model
{
    public const RATINGS = ['on_track' => 'On track', 'at_risk' => 'At risk', 'off_track' => 'Off track'];

    protected $fillable = ['period_from', 'period_to', 'overall', 'summary', 'achievements', 'next_steps', 'help_needed'];

    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'snapshot' => 'array', 'published_at' => 'datetime'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function ref(): string
    {
        return 'SR-'.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT);
    }

    public function published(): bool
    {
        return $this->status === 'published';
    }
}
