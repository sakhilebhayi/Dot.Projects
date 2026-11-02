<?php

namespace App\Livewire\Portfolio;

use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Team;
use App\Services\PortfolioOverview;
use App\Services\Portfolios;
use App\Services\Workload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The executive overview: every active project the person can see, or one portfolio's, with
 * health, delivery, money (where allowed), risks and milestones, and what needs intervention.
 * Portfolios are created by the business owner or admins and managed by them or their owner.
 */
class Board extends Component
{
    #[Url(as: 'portfolio', except: '')]
    public string $portfolio = '';

    public bool $editing = false;

    public ?int $editId = null;

    public string $name = '';

    public string $kind = 'programme';

    public string $owner = '';

    public string $objective = '';

    /** @var list<string> */
    public array $chosen = [];

    private function team(): Team
    {
        $team = auth()->user()->currentTeam;
        abort_unless($team instanceof Team, 403);

        return $team;
    }

    /** @return Collection<int, Project> active projects this person can see */
    private function visibleProjects(): Collection
    {
        return Project::query()->with('team')->whereNull('archived_at')->where('status', '!=', 'completed')->orderBy('name')->get();
    }

    private function current(): ?Portfolio
    {
        if ($this->portfolio === '') {
            return null;
        }
        $p = Portfolio::query()->where('team_id', $this->team()->id)->find((int) $this->portfolio);
        abort_if($p === null, 404);

        return $p;
    }

    public function startCreate(): void
    {
        abort_unless(Workload::oversees(auth()->user(), $this->team()), 403);
        $this->reset('editId', 'name', 'objective', 'owner', 'chosen');
        $this->kind = 'programme';
        $this->editing = true;
        $this->resetErrorBag();
    }

    public function startEdit(): void
    {
        $p = $this->current();
        abort_unless($p !== null && $p->manageableBy(auth()->user()), 403);
        $this->editId = $p->id;
        $this->name = $p->name;
        $this->kind = $p->kind;
        $this->owner = (string) $p->owner_id;
        $this->objective = (string) $p->objective;
        $this->chosen = $p->projects()->pluck('projects.id')->map(fn ($id) => (string) $id)->all();
        $this->editing = true;
        $this->resetErrorBag();
    }

    public function save(Portfolios $portfolios): void
    {
        $this->resetErrorBag();
        $data = ['name' => $this->name, 'kind' => $this->kind, 'objective' => $this->objective, 'owner_id' => $this->owner === '' ? null : (int) $this->owner];
        if ($this->editId === null) {
            $p = $portfolios->create($this->team(), auth()->user(), $data);
        } else {
            $p = Portfolio::query()->where('team_id', $this->team()->id)->findOrFail($this->editId);
            $portfolios->update($p, auth()->user(), $data);
        }
        $portfolios->setProjects($p, auth()->user(), array_map('intval', $this->chosen));
        $this->editing = false;
        $this->portfolio = (string) $p->id;
    }

    public function remove(Portfolios $portfolios): void
    {
        $p = $this->current();
        abort_if($p === null, 404);
        $portfolios->delete($p, auth()->user());
        $this->portfolio = '';
    }

    public function render(): View
    {
        $team = $this->team();
        $me = auth()->user();
        $oversees = Workload::oversees($me, $team);
        $current = $this->current();
        $all = $this->visibleProjects();
        $projects = $current ? $all->whereIn('id', $current->projects()->pluck('projects.id'))->values() : $all;
        $grouped = Portfolio::query()->where('team_id', $team->id)->get();
        $inAny = DB::table('portfolio_project')->whereIn('portfolio_id', $grouped->pluck('id'))->pluck('project_id')->unique();
        $overCapacity = null;
        if ($oversees) {
            $rows = app(Workload::class)->forPeople($team, $team->allUsers(), $me);
            $overCapacity = collect($rows)->filter(fn ($r) => collect($r['weeks'])->contains(fn ($w) => $w['minutes'] > $r['capacity']))->count();
        }

        return view('livewire.portfolio.board', [
            'portfolios' => $grouped,
            'current' => $current,
            'o' => app(PortfolioOverview::class)->build($projects, $me),
            'unaligned' => $all->whereNotIn('id', $inAny)->count(),
            'overCapacity' => $overCapacity,
            'oversees' => $oversees,
            'canManage' => $current?->manageableBy($me) ?? false,
            'people' => $team->allUsers()->sortBy('name')->values(),
            'choosable' => $all,
        ]);
    }
}
