<?php

namespace App\Livewire\Integrations;

use App\Models\IntegrationDelivery;
use App\Models\Team;
use App\Models\User;
use App\Services\Integrations\NotifyClient;
use App\Services\Integrations\Outbox;
use App\Services\Workload;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Integrations (business owner and admins): which Dot platforms Dot.Projects is connected to,
 * whether each is set up on this server and for this business, and what was sent, is waiting or
 * was not sent, with the reason and a way to send it again. It never claims a connection works:
 * it shows what was set up and what actually happened.
 */
class Status extends Component
{
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

    public function sendAgain(int $id, Outbox $outbox): void
    {
        $d = IntegrationDelivery::query()->where('team_id', $this->team()->id)->find($id);
        abort_if($d === null, 404);
        $outbox->again($d);
    }

    public function sendAllAgain(Outbox $outbox): void
    {
        IntegrationDelivery::query()->where('team_id', $this->team()->id)->where('status', 'given_up')->get()->each(fn ($d) => $outbox->again($d));
    }

    public function render(NotifyClient $notify): View
    {
        $team = $this->team();
        $since = Carbon::now()->subDays(7);
        $rows = IntegrationDelivery::query()->where('team_id', $team->id)->where('integration', 'notify');
        $counts = (clone $rows)->where('created_at', '>=', $since)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('livewire.integrations.status', [
            'team' => $team,
            'linked' => filled($team->infodot_team_uuid),
            'infodot' => filled(config('ecosystem.infodot.secret')),
            'notifyOn' => $notify->enabled(),
            'counts' => ['sent' => (int) ($counts['sent'] ?? 0), 'pending' => (int) ($counts['pending'] ?? 0), 'given_up' => (int) ($counts['given_up'] ?? 0)],
            'lastSent' => (clone $rows)->where('status', 'sent')->max('sent_at'),
            'problems' => (clone $rows)->where(fn ($q) => $q->where('status', 'given_up')->orWhere(fn ($r) => $r->where('status', 'pending')->where('attempts', '>', 0)))->latest('id')->limit(10)->get(),
        ]);
    }
}
