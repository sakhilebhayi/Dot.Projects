<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A business's own project template (R-34 P13a), saved from one of its projects. The structure is described in
 * App\Services\ProjectTemplates.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string|null $description
 * @property string|null $methodology
 * @property array<string, mixed> $structure
 * @property int|null $created_by
 * @property int|null $source_project_id
 * @property int $uses
 * @property Carbon $created_at
 */
class ProjectTemplate extends Model
{
    protected $fillable = ['name', 'description', 'methodology'];

    protected $casts = ['structure' => 'array', 'uses' => 'integer'];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
