<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An automation rule: when its trigger happens in its project (or any project in the business),
 * and its conditions hold, its actions run in order. Written through App\Services\Automations.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $project_id
 * @property string $name
 * @property string $trigger
 * @property array<string, mixed> $params
 * @property list<array{field: string, values: list<string>}> $conditions
 * @property list<array<string, mixed>> $actions
 * @property bool $active
 * @property int|null $created_by
 * @property Carbon|null $activated_at
 * @property-read Project|null $project
 * @property-read Team $team
 * @property-read User|null $creator
 */
class AutomationRule extends Model
{
    protected $fillable = ['name', 'trigger', 'params', 'conditions', 'actions'];

    protected $casts = ['params' => 'array', 'conditions' => 'array', 'actions' => 'array', 'active' => 'boolean', 'activated_at' => 'datetime'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<AutomationRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'rule_id')->latest('id');
    }
}
