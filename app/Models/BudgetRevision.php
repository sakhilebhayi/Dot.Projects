<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A change to a budget line's amount: from, to, why, and the approved change request behind it
 * if there is one. Never edited.
 *
 * @property int $id
 * @property int $budget_line_id
 * @property int $from_cents
 * @property int $to_cents
 * @property string $reason
 * @property int|null $change_id
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read BudgetLine $line
 * @property-read ProjectChange|null $change
 * @property-read User|null $creator
 */
class BudgetRevision extends Model
{
    protected $fillable = ['from_cents', 'to_cents', 'reason', 'change_id'];

    protected $casts = ['from_cents' => 'integer', 'to_cents' => 'integer'];

    /** @return BelongsTo<BudgetLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    /** @return BelongsTo<ProjectChange, $this> */
    public function change(): BelongsTo
    {
        return $this->belongsTo(ProjectChange::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
