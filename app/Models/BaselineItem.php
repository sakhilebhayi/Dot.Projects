<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One dated thing in a baseline: a task (start and due), a milestone (due) or the project (due).
 *
 * @property int $id
 * @property int $project_baseline_id
 * @property string $item_type
 * @property int $item_id
 * @property Carbon|null $start_date
 * @property Carbon|null $due_date
 */
class BaselineItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['project_baseline_id', 'item_type', 'item_id', 'start_date', 'due_date'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'due_date' => 'date'];
    }
}
