<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A person's named filter of a project's work table.
 *
 * @property int $id
 * @property int $user_id
 * @property int $project_id
 * @property string $name
 * @property array<string, mixed> $filters
 */
class SavedView extends Model
{
    protected $fillable = ['user_id', 'project_id', 'name', 'filters'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
