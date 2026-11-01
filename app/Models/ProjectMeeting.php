<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A meeting record: who attended, the agenda and the minutes, with the decisions taken and the
 * actions agreed linked to it. Steering committee meetings are one kind. Written through
 * App\Services\Meetings.
 *
 * @property int $id
 * @property int $project_id
 * @property int $number
 * @property string $title
 * @property string $kind
 * @property Carbon $held_at
 * @property string|null $location
 * @property string|null $guests
 * @property string|null $agenda
 * @property string|null $minutes
 * @property int|null $created_by
 * @property-read Project $project
 * @property-read Collection<int, User> $attendees
 * @property-read Collection<int, ProjectAction> $actions
 * @property-read Collection<int, ProjectDecision> $decisions
 */
class ProjectMeeting extends Model
{
    public const KINDS = ['team' => 'Team meeting', 'steering' => 'Steering committee', 'client' => 'Client meeting', 'site' => 'Site meeting', 'review' => 'Review', 'other' => 'Other'];

    protected $fillable = ['title', 'kind', 'held_at', 'location', 'guests', 'agenda', 'minutes'];

    protected $casts = ['held_at' => 'datetime'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_attendees', 'project_meeting_id', 'user_id')->orderBy('name');
    }

    /** @return HasMany<ProjectAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ProjectAction::class, 'meeting_id')->orderBy('number');
    }

    /** @return HasMany<ProjectDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(ProjectDecision::class, 'meeting_id')->orderBy('number');
    }

    public function ref(): string
    {
        return 'M-'.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT);
    }
}
