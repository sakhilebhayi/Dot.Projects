<?php

namespace App\Livewire\Security;

use App\Models\RetentionPolicy;
use App\Models\Team;
use App\Models\User;
use App\Services\DataRetention;
use App\Services\Workload;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Data and security (business owner and admins): how long the business keeps what accumulates, and what
 * protects its data, read from how this server is actually set up rather than claimed.
 */
class DataPage extends Component
{
    public string $activity = '';

    public string $notices = '180';

    public string $automation = '365';

    public bool $saved = false;

    public function mount(DataRetention $retention): void
    {
        $p = $retention->policy($this->team());
        $this->activity = $p->activity_months === null ? '' : (string) $p->activity_months;
        $this->notices = (string) $p->notice_days;
        $this->automation = (string) $p->automation_days;
    }

    private function me(): User
    {
        /** @var User $me */
        $me = auth()->user();

        return $me;
    }

    private function team(): Team
    {
        /** @var Team|null $team */
        $team = $this->me()->currentTeam;
        abort_if($team === null || ! Workload::oversees($this->me(), $team), 403);

        return $team;
    }

    public function save(DataRetention $retention): void
    {
        $this->resetErrorBag();
        try {
            $retention->set($this->team(), $this->me(), $this->activity === '' ? null : (int) $this->activity, (int) $this->notices, (int) $this->automation);
            $this->saved = true;
        } catch (ValidationException $e) {
            $this->addError('retention', (string) collect($e->errors())->flatten()->first());
        }
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    public function render(DataRetention $retention): View
    {
        /** @var RetentionPolicy $policy */
        $policy = $retention->policy($this->team());

        return view('livewire.security.data-page', [
            'policy' => $policy,
            'cspEnforced' => ! config('security.csp_report_only', false),
            'hstsDays' => intdiv((int) config('security.hsts_seconds', 0), 86400),
        ]);
    }
}
