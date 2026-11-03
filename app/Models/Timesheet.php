<?php

namespace App\Models;

use App\Models\Concerns\BumpsProjectFacts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person's week in one business: open while they log, submitted to an approver, then approved
 * (the week's entries are fixed) or returned with a reason (they can change them again).
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property Carbon $week_start
 * @property string $status
 * @property int|null $approver_id
 * @property Carbon|null $submitted_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $return_note
 * @property-read User $user
 * @property-read User|null $approver
 * @property-read User|null $decider
 * @property-read Team $team
 */
class Timesheet extends Model
{
    use BumpsProjectFacts;

    public const STATUSES = ['open' => 'Open', 'submitted' => 'Waiting for approval', 'approved' => 'Approved', 'returned' => 'Returned'];

    protected $fillable = ['team_id', 'user_id', 'week_start'];

    protected $casts = ['week_start' => 'date', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** The week's entries: this person, this business, Monday to Sunday. @return HasMany<TimeEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'user_id', 'user_id')
            ->where('time_entries.team_id', $this->team_id)
            ->whereDate('work_date', '>=', $this->week_start->toDateString())
            ->whereDate('work_date', '<=', $this->week_start->copy()->addDays(6)->toDateString());
    }

    /** Submitted or approved weeks are fixed; open and returned ones can change. */
    public function locked(): bool
    {
        return in_array($this->status, ['submitted', 'approved'], true);
    }

    /** @return list<int> */
    public function factsProjectIds(): array
    {
        return [];
    }

    /** An approval changes labour cost on every project the time went to. */
    public function factsTeamId(): ?int
    {
        return (int) $this->team_id;
    }
}
