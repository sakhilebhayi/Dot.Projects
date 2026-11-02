<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a project's budget: a category, a name, the current budgeted amount and, if someone
 * set it, the cost still to come. Written through App\Services\CostLedger.
 *
 * @property int $id
 * @property int $project_id
 * @property string $category
 * @property string $name
 * @property int $budget_cents
 * @property int|null $to_complete_cents
 * @property int $sort_order
 * @property-read Project $project
 */
class BudgetLine extends Model
{
    public const CATEGORIES = ['labour' => 'Labour', 'contractors' => 'Contractors', 'materials' => 'Materials', 'equipment' => 'Equipment', 'operational' => 'Operational', 'contingency' => 'Contingency', 'other' => 'Other'];

    protected $fillable = ['category', 'name', 'sort_order'];

    protected $casts = ['budget_cents' => 'integer', 'to_complete_cents' => 'integer'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<CostItem, $this> */
    public function costs(): HasMany
    {
        return $this->hasMany(CostItem::class);
    }

    /** @return HasMany<BudgetRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(BudgetRevision::class)->latest('id');
    }
}
