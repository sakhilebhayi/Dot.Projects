<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A project's reference to a document filed in Dot.Files (App\Services\ProjectDocuments).
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $task_id
 * @property string $files_uuid
 * @property string $name
 * @property int $size
 * @property string|null $mime
 * @property string|null $reference
 * @property string|null $open_url
 * @property int|null $version
 * @property int|null $uploaded_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Project $project
 * @property-read ProjectTask|null $task
 * @property-read User|null $uploader
 */
class ProjectDocument extends Model
{
    protected $fillable = ['task_id', 'files_uuid', 'name', 'size', 'mime', 'reference', 'open_url', 'version'];

    protected $casts = ['size' => 'integer', 'version' => 'integer'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** "1.2 MB", "340 KB". */
    public function sizeLabel(): string
    {
        return $this->size >= 1048576 ? number_format($this->size / 1048576, 1).' MB' : max(1, (int) round($this->size / 1024)).' KB';
    }
}
