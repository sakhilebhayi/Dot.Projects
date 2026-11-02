<?php

namespace App\Livewire\Workload;

use App\Models\Team;
use App\Services\LabourCost;
use App\Services\Workload;
use App\Support\Duration;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Planned load against capacity for the next six weeks, per person, in the current business. The
 * business owner and admins see everyone and set capacity; everyone else sees their own row.
 * Choosing a week shows the tasks behind it.
 */
class Board extends Component
{
    #[Url(as: 'focus', except: '')]
    public string $focus = '';

    public ?int $editing = null;

    public string $hours = '';

    public string $note = '';

    public ?int $rating = null;

    public string $rate = '';

    public string $rateFrom = '';

    private function team(): Team
    {
        $team = auth()->user()->currentTeam;
        abort_unless($team instanceof Team, 403);

        return $team;
    }

    public function pick(int $userId, string $week): void
    {
        $key = $userId.'|'.$week;
        $this->focus = $this->focus === $key ? '' : $key;
    }

    public function editCapacity(int $userId): void
    {
        abort_unless(Workload::oversees(auth()->user(), $this->team()), 403);
        $cap = app(Workload::class)->capacities($this->team())[$userId] ?? ['minutes' => Workload::DEFAULT_MINUTES, 'note' => null];
        $this->editing = $userId;
        $this->hours = rtrim(rtrim(number_format($cap['minutes'] / 60, 2, '.', ''), '0'), '.');
        $this->note = (string) $cap['note'];
        $this->resetErrorBag();
    }

    public function saveCapacity(Workload $workload): void
    {
        abort_unless(Workload::oversees(auth()->user(), $this->team()) && $this->editing !== null, 403);
        $this->resetErrorBag();
        $minutes = trim($this->hours) === '0' ? 0 : Duration::parse($this->hours);
        if ($minutes === null) {
            throw ValidationException::withMessages(['capacity' => 'Give the project hours a week, such as 40 or 32.5.']);
        }
        $workload->setCapacity($this->team(), auth()->user(), $this->editing, $minutes, $this->note);
        $this->reset('editing', 'hours', 'note');
    }

    public function editRate(int $userId): void
    {
        abort_unless(Workload::oversees(auth()->user(), $this->team()), 403);
        $current = LabourCost::rateOn(app(LabourCost::class)->rates($this->team()), $userId, Carbon::today());
        $this->rating = $userId;
        $this->rate = $current === null ? '' : rtrim(rtrim(number_format($current / 100, 2, '.', ''), '0'), '.');
        $this->rateFrom = Carbon::today()->toDateString();
        $this->resetErrorBag();
    }

    public function saveRate(LabourCost $labour): void
    {
        abort_unless(Workload::oversees(auth()->user(), $this->team()) && $this->rating !== null, 403);
        $this->resetErrorBag();
        $labour->setRate($this->team(), auth()->user(), $this->rating, Money::parse($this->rate), $this->rateFrom);
        $this->reset('rating', 'rate', 'rateFrom');
    }

    public function render(): View
    {
        $team = $this->team();
        $me = auth()->user();
        $oversees = Workload::oversees($me, $team);
        $people = $oversees ? $team->allUsers()->sortBy('name')->values() : collect([$me]);
        $rows = app(Workload::class)->forPeople($team, $people, $me);
        [$fUser, $fWeek] = array_pad(explode('|', $this->focus, 2), 2, null);
        $focused = collect($rows)->first(fn ($r) => (string) $r['user']->id === $fUser);

        return view('livewire.workload.board', [
            'rows' => $rows,
            'oversees' => $oversees,
            // Rates are only read for those allowed to see them.
            'rates' => $oversees ? app(LabourCost::class)->rates($team) : [],
            'focused' => $focused && $fWeek !== null && isset($focused['weeks'][$fWeek]) ? ['row' => $focused, 'week' => $fWeek] : null,
        ]);
    }
}
