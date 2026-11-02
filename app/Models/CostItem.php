<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A cost on a budget line: a commitment (an order or contract placed) or an actual (an invoice or
 * expense). An actual may settle a commitment, which then counts only for what is still open on it.
 *
 * @property int $id
 * @property int $project_id
 * @property int $budget_line_id
 * @property string $kind
 * @property string $description
 * @property string|null $supplier
 * @property string|null $reference
 * @property int $amount_cents
 * @property Carbon $dated
 * @property int|null $settles_id
 * @property int|null $created_by
 * @property-read BudgetLine $line
 * @property-read CostItem|null $settles
 * @property-read User|null $creator
 */
class CostItem extends Model
{
    public const KINDS = ['commitment' => 'Commitment', 'actual' => 'Actual'];

    protected $fillable = ['budget_line_id', 'kind', 'description', 'supplier', 'reference', 'amount_cents', 'dated', 'settles_id'];

    protected $casts = ['amount_cents' => 'integer', 'dated' => 'date'];

    /** @return BelongsTo<BudgetLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    /** @return BelongsTo<CostItem, $this> */
    public function settles(): BelongsTo
    {
        return $this->belongsTo(CostItem::class, 'settles_id');
    }

    /** Actuals that settle this commitment. @return HasMany<CostItem, $this> */
    public function settledBy(): HasMany
    {
        return $this->hasMany(CostItem::class, 'settles_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
