<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One firing of a rule. The key is unique per rule, so the same event (or the same state, for
 * rules that fire once per risk, project or person) never runs twice. Steps record each action's
 * outcome, so a retry repeats only the steps that did not finish.
 *
 * @property int $id
 * @property int $rule_id
 * @property int $team_id
 * @property int|null $project_id
 * @property int|null $activity_id
 * @property string $key
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $what what set it off, in a sentence
 * @property string $status done, failed or skipped
 * @property list<array{action: string, ok: bool, summary: string}> $steps
 * @property string|null $error
 * @property int $attempts
 * @property Carbon|null $resume_at when a waiting run carries on
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read AutomationRule $rule
 * @property-read Project|null $project
 */
class AutomationRun extends Model
{
    public const STATUSES = ['done' => 'Done', 'failed' => 'Failed', 'skipped' => 'Nothing to do', 'waiting' => 'Waiting'];

    protected $casts = ['steps' => 'array', 'attempts' => 'integer', 'resume_at' => 'datetime'];

    /** @return BelongsTo<AutomationRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
