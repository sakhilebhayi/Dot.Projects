<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a task's checklist. Reached only through its task, whose policy decides access.
 *
 * @property int $id
 * @property int $project_task_id
 * @property string $body
 * @property int $position
 * @property Carbon|null $done_at
 * @property int|null $done_by
 */
class ChecklistItem extends Model
{
    protected $table = 'task_checklist_items';

    protected $fillable = ['project_task_id', 'body', 'position'];

    protected function casts(): array
    {
        return ['done_at' => 'datetime', 'position' => 'integer'];
    }

    /** @return BelongsTo<ProjectTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }
}
