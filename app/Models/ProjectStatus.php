<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a project's own task statuses (R-34 P13c), in one of the five fixed categories.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $category backlog, todo, in_progress, review or done
 * @property int $sort_order
 */
class ProjectStatus extends Model
{
    protected $fillable = ['name', 'category', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
