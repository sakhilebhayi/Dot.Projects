<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A label in one project ("Customs", "Kathu", "Safety"). Text only: labels never carry a colour,
 * so colour stays reserved for the critical path and for what needs action.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 */
class ProjectLabel extends Model
{
    protected $fillable = ['project_id', 'name'];

    /** @return BelongsToMany<ProjectTask, $this> */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(ProjectTask::class, 'project_label_task');
    }
}
