<?php

namespace App\Models;

use App\Models\Concerns\BumpsProjectFacts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One line of a gate's governance checklist, ticked by a person.
 *
 * @property int $id
 * @property int $project_gate_id
 * @property string $body
 * @property int $sort_order
 * @property Carbon|null $done_at
 * @property int|null $done_by
 * @property-read User|null $doer
 */
class GateCheck extends Model
{
    use BumpsProjectFacts;

    protected $fillable = ['body', 'sort_order'];

    protected $casts = ['done_at' => 'datetime'];

    /** @return BelongsTo<ProjectGate, $this> */
    public function gate(): BelongsTo
    {
        return $this->belongsTo(ProjectGate::class, 'project_gate_id');
    }

    /** @return BelongsTo<User, $this> */
    public function doer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    /** @return list<int> the gate's project */
    public function factsProjectIds(): array
    {
        $project = DB::table('project_gates')->where('id', $this->project_gate_id)->value('project_id');

        return $project !== null ? [(int) $project] : [];
    }
}
