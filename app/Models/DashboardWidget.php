<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One widget on a dashboard: its kind (App\Services\DashboardWidgets::KINDS), an optional title,
 * its settings, its place and whether it spans the full width.
 *
 * @property int $id
 * @property int $dashboard_id
 * @property string $kind
 * @property string|null $title
 * @property array<string, mixed> $config
 * @property int $position
 * @property bool $wide
 * @property-read Dashboard $dashboard
 */
class DashboardWidget extends Model
{
    protected $fillable = ['kind', 'title', 'config', 'position', 'wide'];

    protected $casts = ['config' => 'array', 'wide' => 'boolean', 'position' => 'integer'];

    /** @return BelongsTo<Dashboard, $this> */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }
}
