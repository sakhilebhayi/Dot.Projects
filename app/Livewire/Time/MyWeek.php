<?php

namespace App\Livewire\Time;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Team;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Services\TimeTracking;
use App\Support\Duration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * @property-read Carbon $monday
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, TimeEntry> $entries
 * @property-read Timesheet|null $sheet
 * @property-read Collection<int, Timesheet> $waiting
 *
 * The signed-in person's time for one week in their current business: a line per day, entries to
 * add, change or remove while the week is open, the timer, sending the week for approval, and the
 * weeks other people sent to them to approve.
 */
class MyWeek extends Component
{
    #[Url(as: 'week', except: '')]
    public string $week = '';

    #[Url(as: 'approve', except: '')]
    public string $approve = '';

    public string $project = '';

    public string $task = '';

    public string $date = '';

    public string $hours = '';

    public string $note = '';

    public bool $billable = false;

    public ?int $editing = null;

    public string $approver = '';

    public string $returnNote = '';

    public function mount(): void
    {
        $this->date = Carbon::today()->toDateString();
        $first = $this->projects->first();
        $this->project = $first ? (string) $first->id : '';
    }

    #[Computed]
    public function monday(): Carbon
    {
        try {
            $day = $this->week === '' ? Carbon::today() : Carbon::parse($this->week);
        } catch (\Throwable) {
            $day = Carbon::today();
        }

        return TimeTracking::weekStart($day);
    }

    /** Projects the person can log on in this business. */
    #[Computed]
    public function projects(): Collection
    {
        return Project::query()->whereNull('archived_at')->orderBy('name')->get()->filter(fn (Project $p) => $p->canBeChangedBy(auth()->user()))->values();
    }

    #[Computed]
    public function entries(): Collection
    {
        return TimeEntry::query()->where('user_id', auth()->id())->where('team_id', auth()->user()->current_team_id)
            ->whereDate('work_date', '>=', $this->monday->toDateString())->whereDate('work_date', '<=', $this->monday->copy()->addDays(6)->toDateString())
            ->with(['project' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'), 'task:id,title'])->orderBy('work_date')->orderBy('id')->get();
    }

    #[Computed]
    public function sheet(): ?Timesheet
    {
        return Timesheet::query()->where('user_id', auth()->id())->where('team_id', auth()->user()->current_team_id)->whereDate('week_start', $this->monday->toDateString())->with('approver:id,name', 'decider:id,name')->first();
    }

    /** Weeks others sent to this person, waiting for a decision. */
    #[Computed]
    public function waiting(): Collection
    {
        return Timesheet::query()->where('approver_id', auth()->id())->where('team_id', auth()->user()->current_team_id)->where('status', 'submitted')->with('user:id,name')->orderBy('week_start')->get();
    }

    public function shiftWeek(int $by): void
    {
        $this->week = $this->monday->copy()->addWeeks($by)->toDateString();
        $this->resetErrorBag();
        unset($this->monday, $this->entries, $this->sheet);
    }

    public function thisWeek(): void
    {
        $this->week = '';
        unset($this->monday, $this->entries, $this->sheet);
    }

    private function chosenProject(): Project
    {
        $p = $this->projects->firstWhere('id', (int) $this->project);
        if ($p === null) {
            throw ValidationException::withMessages(['project' => 'Choose a project you work on.']);
        }

        return $p;
    }

    private function parsedMinutes(): int
    {
        $m = Duration::parse($this->hours);
        if ($m === null) {
            throw ValidationException::withMessages(['minutes' => 'Give the time as hours and minutes, such as 1:30, 1.5 or 45m.']);
        }

        return $m;
    }

    public function save(TimeTracking $time): void
    {
        $this->resetErrorBag();
        $data = ['task_id' => $this->task === '' ? null : (int) $this->task, 'work_date' => $this->date, 'minutes' => $this->parsedMinutes(), 'note' => $this->note, 'billable' => $this->billable];
        if ($this->editing !== null) {
            $time->update($this->own($this->editing), auth()->user(), $data);
        } else {
            $time->log(auth()->user(), $this->chosenProject(), $data);
        }
        $this->reset('hours', 'note', 'editing', 'task');
        unset($this->entries);
    }

    public function edit(int $id): void
    {
        $e = $this->own($id);
        $this->editing = $e->id;
        $this->project = (string) $e->project_id;
        $this->task = (string) $e->task_id;
        $this->date = $e->work_date->toDateString();
        $this->hours = Duration::format((int) $e->minutes);
        $this->note = (string) $e->note;
        $this->billable = $e->billable;
    }

    public function cancelEdit(): void
    {
        $this->reset('hours', 'note', 'editing', 'task');
        $this->date = Carbon::today()->toDateString();
    }

    public function delete(int $id, TimeTracking $time): void
    {
        $this->resetErrorBag();
        $time->delete($this->own($id), auth()->user());
        unset($this->entries);
    }

    public function startTimer(TimeTracking $time): void
    {
        $this->resetErrorBag();
        $time->start(auth()->user(), $this->chosenProject(), $this->task === '' ? null : (int) $this->task, $this->note, $this->billable);
        $this->reset('note');
        unset($this->entries);
    }

    public function stopTimer(TimeTracking $time): void
    {
        $time->stop(auth()->user());
        unset($this->entries);
    }

    public function submit(TimeTracking $time): void
    {
        $this->resetErrorBag();
        $team = auth()->user()->currentTeam;
        abort_unless($team instanceof Team, 403);
        $sheet = $time->sheet(auth()->user(), $team, $this->monday);
        $time->submit($sheet, auth()->user(), $this->approver === '' ? null : (int) $this->approver);
        unset($this->sheet);
    }

    private function waitingSheet(int $id): Timesheet
    {
        $sheet = $this->waiting->firstWhere('id', $id);
        abort_if($sheet === null, 404);

        return $sheet;
    }

    public function approveSheet(int $id, TimeTracking $time): void
    {
        $this->resetErrorBag();
        $time->approve($this->waitingSheet($id), auth()->user());
        $this->reset('approve');
        unset($this->waiting);
    }

    public function returnSheet(int $id, TimeTracking $time): void
    {
        $this->resetErrorBag();
        $time->sendBack($this->waitingSheet($id), auth()->user(), $this->returnNote);
        $this->reset('approve', 'returnNote');
        unset($this->waiting);
    }

    private function own(int $id): TimeEntry
    {
        $e = TimeEntry::query()->where('user_id', auth()->id())->find($id);
        abort_if($e === null, 404);

        return $e;
    }

    public function render(): View
    {
        $time = app(TimeTracking::class);
        $sheet = $this->sheet ?? new Timesheet(['team_id' => auth()->user()->current_team_id, 'user_id' => auth()->id(), 'week_start' => $this->monday]);
        $tasks = $this->project === '' ? collect() : ProjectTask::query()->where('project_id', (int) $this->project)->where('status', '!=', 'done')->orderBy('title')->get(['id', 'title']);
        $review = $this->approve === '' ? null : $this->waiting->firstWhere('id', (int) $this->approve);

        return view('livewire.time.my-week', [
            'running' => $time->running(auth()->user()),
            'approvers' => $sheet->exists || $this->entries->isNotEmpty() ? $time->approversFor($sheet) : collect(),
            'tasks' => $tasks,
            'review' => $review,
            'reviewEntries' => $review ? $review->entries()->with(['project' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'), 'task:id,title'])->orderBy('work_date')->get() : collect(),
        ]);
    }
}
