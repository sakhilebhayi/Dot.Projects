<?php

namespace App\Livewire\Dashboards;

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Portfolio;
use App\Models\SavedReport;
use App\Models\Team;
use App\Models\User;
use App\Services\Dashboards;
use App\Services\DashboardWidgets;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Dashboards: your own arrangements of widgets, and those shared with the business. Make one,
 * arrange it, share it; copy a shared one to make it yours. Every widget is worked out for the
 * person looking (App\Services\DashboardWidgets).
 */
class Board extends Component
{
    #[Url(as: 'd')]
    public string $dashboard = '';

    public bool $creating = false;

    public string $newName = '';

    public bool $editing = false;

    public string $name = '';

    public bool $shared = false;

    public string $wKind = 'report';

    public string $wSource = 'executive';

    public string $wPortfolio = '';

    public string $wRows = '5';

    public string $wTitle = '';

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
        abort_if($team === null, 404);

        return $team;
    }

    /** @return Collection<int, Dashboard> */
    private function readable(): Collection
    {
        $me = $this->me();

        return Dashboard::query()->where('team_id', $this->team()->id)->where(fn ($q) => $q->where('user_id', $me->id)->orWhere('shared', true))
            ->with('user:id,name')->orderByRaw('user_id = ? desc', [$me->id])->orderBy('name')->get();
    }

    private function current(): ?Dashboard
    {
        $all = $this->readable();
        if ($this->dashboard !== '') {
            $d = Dashboard::query()->where('team_id', $this->team()->id)->find((int) $this->dashboard);
            abort_if($d === null || ! $d->readableBy($this->me()), 404);

            return $d;
        }

        return $all->first();
    }

    private function managed(): Dashboard
    {
        $d = $this->current();
        abort_if($d === null, 404);
        abort_unless($d->manageableBy($this->me()), 403);

        return $d;
    }

    public function updatedDashboard(): void
    {
        $this->reset('editing', 'creating');
        $this->resetErrorBag();
    }

    public function create(Dashboards $dashboards): void
    {
        $this->resetErrorBag();
        $d = $dashboards->create($this->team(), $this->me(), $this->newName);
        $this->dashboard = (string) $d->id;
        $this->reset('creating', 'newName');
        $this->startEdit();
    }

    /** A first dashboard with the widgets most people start from. */
    public function starter(Dashboards $dashboards): void
    {
        $d = $dashboards->create($this->team(), $this->me(), 'My dashboard');
        foreach (['my_work', 'my_time', 'health', 'risks', 'roadmap'] as $kind) {
            $dashboards->addWidget($d, $this->me(), $kind, [], null);
        }
        $this->dashboard = (string) $d->id;
    }

    public function startEdit(): void
    {
        $d = $this->managed();
        [$this->name, $this->shared, $this->editing] = [$d->name, $d->shared, true];
        $this->resetErrorBag();
    }

    public function saveSettings(Dashboards $dashboards): void
    {
        $this->resetErrorBag();
        $dashboards->rename($this->managed(), $this->me(), $this->name, $this->shared);
        $this->editing = false;
    }

    public function remove(Dashboards $dashboards): void
    {
        $dashboards->delete($this->managed(), $this->me());
        $this->reset('dashboard', 'editing');
    }

    public function copy(Dashboards $dashboards): void
    {
        $d = $this->current();
        abort_if($d === null, 404);
        $this->dashboard = (string) $dashboards->copy($d, $this->me())->id;
        $this->editing = false;
    }

    public function addWidget(Dashboards $dashboards): void
    {
        $this->resetErrorBag();
        $dashboards->addWidget($this->managed(), $this->me(), $this->wKind, ['source' => $this->wSource, 'portfolio' => $this->wPortfolio, 'rows' => $this->wRows], $this->wTitle);
        $this->reset('wTitle');
    }

    private function widget(int $id): DashboardWidget
    {
        $w = $this->managed()->widgets()->find($id);
        abort_if($w === null, 404);

        return $w;
    }

    public function moveWidget(int $id, int $step, Dashboards $dashboards): void
    {
        $dashboards->move($this->widget($id), $this->me(), $step);
    }

    public function toggleWide(int $id, Dashboards $dashboards): void
    {
        $dashboards->toggleWide($this->widget($id), $this->me());
    }

    public function removeWidget(int $id, Dashboards $dashboards): void
    {
        $dashboards->removeWidget($this->widget($id), $this->me());
    }

    public function render(DashboardWidgets $widgets): View
    {
        $team = $this->team();
        $me = $this->me();
        $current = $this->current();
        $items = [];
        if ($current !== null) {
            foreach ($current->widgets()->get() as $w) {
                $data = $widgets->data($w, $team, $me);
                $items[] = ['widget' => $w, 'data' => $data, 'heading' => DashboardWidgets::heading($w, $data['name'] ?? null)];
            }
        }

        return view('livewire.dashboards.board', [
            'all' => $this->readable(),
            'current' => $current,
            'canManage' => $current?->manageableBy($me) ?? false,
            'items' => $items,
            'portfolios' => Portfolio::query()->where('team_id', $team->id)->orderBy('name')->get(['id', 'name']),
            'savedReports' => SavedReport::query()->where('team_id', $team->id)->where(fn ($q) => $q->where('user_id', $me->id)->orWhere('shared', true))->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
