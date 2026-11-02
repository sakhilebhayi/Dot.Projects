<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Team;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Notifications\GovernanceNotification;
use App\Support\Duration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Time people log themselves and the weekly timesheets that carry it to approval. A person logs
 * only on projects they can change; a submitted or approved week is fixed; a timesheet is decided
 * by the approver it was sent to. Nothing here measures activity: only what people enter.
 */
final class TimeTracking
{
    /** Longest single entry, and the most a day can hold. */
    private const MAX_MINUTES = 24 * 60;

    public static function weekStart(Carbon $day): Carbon
    {
        return $day->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /** @param array{task_id?: int|null, work_date: string, minutes: int|null, note?: string|null, billable?: bool} $data */
    public function log(User $by, Project $project, array $data): TimeEntry
    {
        $this->assertCanLog($by, $project);
        $task = $this->task($project, $data['task_id'] ?? null);
        $date = $this->date($data['work_date']);
        $minutes = $this->minutes($data['minutes'] ?? null);
        $this->assertWeekOpen($by, $project->team, $date);
        $this->assertDayRoom($by, $project->team_id, $date, $minutes, null);

        $e = new TimeEntry(['project_id' => $project->id, 'task_id' => $task?->id, 'work_date' => $date, 'minutes' => $minutes, 'note' => $this->note($data['note'] ?? null), 'billable' => (bool) ($data['billable'] ?? false)]);
        $e->forceFill(['team_id' => $project->team_id, 'user_id' => $by->id])->save();

        return $e;
    }

    /** @param array{task_id?: int|null, work_date?: string, minutes?: int|null, note?: string|null, billable?: bool} $data */
    public function update(TimeEntry $e, User $by, array $data): void
    {
        $this->assertOwn($e, $by);
        if ($e->running()) {
            throw ValidationException::withMessages(['entry' => 'Stop the timer before changing this entry.']);
        }
        $this->assertWeekOpen($by, $e->project->team, $e->work_date);
        $date = array_key_exists('work_date', $data) ? $this->date($data['work_date']) : $e->work_date;
        $minutes = array_key_exists('minutes', $data) ? $this->minutes($data['minutes']) : (int) $e->minutes;
        $this->assertWeekOpen($by, $e->project->team, $date);
        $this->assertDayRoom($by, $e->team_id, $date, $minutes, $e->id);
        $task = array_key_exists('task_id', $data) ? $this->task($e->project, $data['task_id']) : $e->task;
        $e->fill(['work_date' => $date, 'minutes' => $minutes, 'task_id' => $task?->id]
            + (array_key_exists('note', $data) ? ['note' => $this->note($data['note'])] : [])
            + (array_key_exists('billable', $data) ? ['billable' => (bool) $data['billable']] : []))->save();
    }

    public function delete(TimeEntry $e, User $by): void
    {
        $this->assertOwn($e, $by);
        $this->assertWeekOpen($by, $e->project->team, $e->work_date);
        $e->delete();
    }

    /** Starts a timer, stopping any other one first: a person has one clock running at a time. */
    public function start(User $by, Project $project, ?int $taskId, ?string $note, bool $billable): TimeEntry
    {
        $this->assertCanLog($by, $project);
        $task = $this->task($project, $taskId);
        $this->assertWeekOpen($by, $project->team, Carbon::today());

        return DB::transaction(function () use ($by, $project, $task, $note, $billable) {
            $running = $this->running($by);
            if ($running !== null) {
                $this->stop($by);
            }
            $e = new TimeEntry(['project_id' => $project->id, 'task_id' => $task?->id, 'work_date' => Carbon::today(), 'minutes' => null, 'note' => $this->note($note), 'billable' => $billable]);
            $e->forceFill(['team_id' => $project->team_id, 'user_id' => $by->id, 'running_since' => Carbon::now()])->save();

            return $e;
        });
    }

    /** Stops the running timer, rounding up to the minute and never beyond a day. */
    public function stop(User $by): ?TimeEntry
    {
        $e = $this->running($by);
        if ($e === null) {
            return null;
        }
        $e->forceFill(['minutes' => min(self::MAX_MINUTES, $e->elapsed()), 'running_since' => null])->save();

        return $e;
    }

    public function running(User $by): ?TimeEntry
    {
        return TimeEntry::query()->where('user_id', $by->id)->whereNotNull('running_since')->with('project:id,name,team_id', 'task:id,title')->first();
    }

    /** The person's sheet for the week (created when first needed). */
    public function sheet(User $user, Team $team, Carbon $day): Timesheet
    {
        $monday = self::weekStart($day)->toDateString();

        // Matched by date, not by the stored value, which some databases keep with a time.
        return Timesheet::query()->where('team_id', $team->id)->where('user_id', $user->id)->whereDate('week_start', $monday)->first()
            ?? Timesheet::query()->create(['team_id' => $team->id, 'user_id' => $user->id, 'week_start' => $monday]);
    }

    /**
     * Who may approve a person's week: in the business, able to change projects, and running one
     * of the projects the week's time is on (its owner or a project manager), or the business
     * owner. Never the person themselves, unless they own the business.
     *
     * @return Collection<int, User>
     */
    public function approversFor(Timesheet $sheet): Collection
    {
        $team = $sheet->team;
        $projects = Project::withoutTeamScope(fn () => Project::query()->with('team')->whereIn('id', $sheet->entries()->pluck('project_id')->unique())->get());

        return $team->allUsers()->filter(function (User $u) use ($team, $projects, $sheet) {
            if ($u->ownsTeam($team)) {
                return true;
            }
            if ($u->id === $sheet->user_id || ! $u->hasTeamPermission($team, 'update')) {
                return false;
            }

            return $projects->contains(fn (Project $p) => $p->canBeChangedBy($u) && ($u->id === $p->owner_id || $p->roleOf($u) === 'manager'));
        })->sortBy('name')->values();
    }

    public function submit(Timesheet $sheet, User $by, ?int $approverId): void
    {
        if ($sheet->user_id !== $by->id) {
            throw ValidationException::withMessages(['timesheet' => 'Only the person whose week it is submits it.']);
        }
        if ($sheet->locked()) {
            throw ValidationException::withMessages(['timesheet' => 'This week has already been submitted.']);
        }
        /** @var \Illuminate\Database\Eloquent\Collection<int, TimeEntry> $entries */
        $entries = $sheet->entries()->get();
        if ($entries->contains(fn (TimeEntry $e) => $e->running())) {
            throw ValidationException::withMessages(['timesheet' => 'Stop the timer running in this week first.']);
        }
        if ($entries->sum('minutes') === 0) {
            throw ValidationException::withMessages(['timesheet' => 'There is no time in this week to submit.']);
        }
        $approver = $this->approversFor($sheet)->firstWhere('id', $approverId);
        if ($approver === null) {
            throw ValidationException::withMessages(['approver' => 'Choose who approves it: someone who runs one of these projects, or the business owner.']);
        }
        $sheet->forceFill(['status' => 'submitted', 'approver_id' => $approver->id, 'submitted_at' => Carbon::now(), 'return_note' => null])->save();
        if ($approver->id !== $by->id) {
            $approver->notify(new GovernanceNotification('timesheet_submitted', 'A timesheet waits for your approval', $by->name.' sent their week of '.$sheet->week_start->format('j M').' ('.Duration::format((int) $entries->sum('minutes')).') for approval.', 0, route('time.index', ['week' => $sheet->week_start->toDateString(), 'approve' => $sheet->id])));
        }
    }

    public function approve(Timesheet $sheet, User $by): void
    {
        $this->assertApprover($sheet, $by);
        $sheet->forceFill(['status' => 'approved', 'decided_by' => $by->id, 'decided_at' => Carbon::now()])->save();
        if ($sheet->user_id !== $by->id) {
            $sheet->user->notify(new GovernanceNotification('timesheet_approved', 'Timesheet approved', $by->name.' approved your week of '.$sheet->week_start->format('j M').'.', 0, route('time.index', ['week' => $sheet->week_start->toDateString()])));
        }
    }

    public function sendBack(Timesheet $sheet, User $by, string $note): void
    {
        $this->assertApprover($sheet, $by);
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['return_note' => 'Say what needs changing, in up to 2000 characters.']);
        }
        $sheet->forceFill(['status' => 'returned', 'decided_by' => $by->id, 'decided_at' => Carbon::now(), 'return_note' => $note])->save();
        $sheet->user->notify(new GovernanceNotification('timesheet_returned', 'Timesheet returned', $by->name.' returned your week of '.$sheet->week_start->format('j M').': '.mb_strimwidth($note, 0, 140, '…'), 0, route('time.index', ['week' => $sheet->week_start->toDateString()])));
    }

    private function assertApprover(Timesheet $sheet, User $by): void
    {
        if ($sheet->status !== 'submitted') {
            throw ValidationException::withMessages(['timesheet' => 'Only a submitted week can be decided.']);
        }
        if ($sheet->approver_id !== $by->id) {
            throw ValidationException::withMessages(['timesheet' => 'Only the approver it was sent to decides it.']);
        }
    }

    private function assertCanLog(User $by, Project $project): void
    {
        if (! $project->canBeChangedBy($by)) {
            throw ValidationException::withMessages(['project' => 'You can log time only on projects you work on.']);
        }
    }

    private function assertOwn(TimeEntry $e, User $by): void
    {
        if ($e->user_id !== $by->id) {
            throw ValidationException::withMessages(['entry' => 'People change only their own time.']);
        }
    }

    private function assertWeekOpen(User $by, Team $team, Carbon $date): void
    {
        $sheet = Timesheet::query()->where('team_id', $team->id)->where('user_id', $by->id)->whereDate('week_start', self::weekStart($date)->toDateString())->first();
        if ($sheet !== null && $sheet->locked()) {
            throw ValidationException::withMessages(['work_date' => 'The week of '.$sheet->week_start->format('j M').' is '.mb_strtolower(Timesheet::STATUSES[$sheet->status]).', so its time is fixed.']);
        }
    }

    private function assertDayRoom(User $by, int $teamId, Carbon $date, int $minutes, ?int $except): void
    {
        $day = (int) TimeEntry::query()->where('team_id', $teamId)->where('user_id', $by->id)->whereDate('work_date', $date->toDateString())
            ->when($except, fn ($q) => $q->whereKeyNot($except))->sum('minutes');
        if ($day + $minutes > self::MAX_MINUTES) {
            throw ValidationException::withMessages(['minutes' => 'A day holds 24 hours; '.$date->format('j M').' already has '.Duration::format($day).'.']);
        }
    }

    private function task(Project $project, mixed $taskId): ?ProjectTask
    {
        if ($taskId === null || $taskId === '') {
            return null;
        }
        $task = ProjectTask::query()->where('project_id', $project->id)->find((int) $taskId);
        if ($task === null) {
            throw ValidationException::withMessages(['task_id' => 'Choose a task of this project.']);
        }

        return $task;
    }

    private function date(mixed $value): Carbon
    {
        try {
            $d = Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['work_date' => 'Give the day the work was done.']);
        }
        if ($d->gt(Carbon::today())) {
            throw ValidationException::withMessages(['work_date' => 'Time is logged for work already done, not for days ahead.']);
        }

        return $d;
    }

    private function minutes(mixed $value): int
    {
        if (! is_int($value) || $value < 1 || $value > self::MAX_MINUTES) {
            throw ValidationException::withMessages(['minutes' => 'Give the time as hours and minutes, such as 1:30 or 1.5, up to 24 hours.']);
        }

        return $value;
    }

    private function note(mixed $note): ?string
    {
        $note = trim((string) $note);
        if (mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['note' => 'Keep the note under 500 characters.']);
        }

        return $note === '' ? null : $note;
    }
}
