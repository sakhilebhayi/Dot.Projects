<?php

namespace App\Livewire\Reporting;

use App\Models\Portfolio;
use App\Models\Project;
use App\Models\SavedReport;
use App\Models\Team;
use App\Models\User;
use App\Services\ReportExport;
use App\Services\Reporting;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Reports page: choose a report, narrow it, pick and order its columns, read it, download it
 * as CSV, Excel or PDF, and keep it under a name, for yourself or for the business. Every report
 * is worked out for the person reading it (App\Services\Reporting).
 */
class Builder extends Component
{
    /** Rows drawn on the page; the files carry every row. */
    public const SHOWN = 300;

    #[Url]
    public string $type = 'executive';

    #[Url(as: 'saved')]
    public string $savedId = '';

    public string $scope = 'open';

    public string $portfolio = '';

    /** @var list<string> */
    public array $projects = [];

    public string $period = 'this_month';

    public string $from = '';

    public string $to = '';

    /** @var list<string> */
    public array $columns = [];

    public ?string $sort = null;

    public bool $desc = false;

    public bool $saving = false;

    public string $name = '';

    public bool $shared = false;

    public function mount(): void
    {
        if ($this->savedId !== '') {
            $this->open((int) $this->savedId);

            return;
        }
        if (! isset(Reporting::REPORTS[$this->type])) {
            $this->type = 'executive';
        }
        $this->columns = Reporting::defaultColumns($this->type);
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
        abort_if($team === null, 404);

        return $team;
    }

    public function choose(string $type): void
    {
        abort_unless(isset(Reporting::REPORTS[$type]), 404);
        $this->type = $type;
        $this->columns = Reporting::defaultColumns($type);
        $this->reset('sort', 'desc', 'savedId', 'saving', 'name', 'shared');
        $this->resetErrorBag();
    }

    public function open(int $id): void
    {
        $saved = SavedReport::query()->where('team_id', $this->team()->id)->find($id);
        abort_if($saved === null || ! $saved->readableBy($this->me()) || ! isset(Reporting::REPORTS[$saved->type]), 404);
        $c = Reporting::normalise($saved->type, $saved->config);
        $this->type = $saved->type;
        $this->savedId = (string) $saved->id;
        $this->scope = $c['filters']['scope'];
        $this->portfolio = $c['filters']['portfolio'];
        $this->projects = array_map('strval', $c['filters']['projects']);
        $this->period = $c['filters']['period'];
        $this->from = $c['filters']['from'];
        $this->to = $c['filters']['to'];
        $this->columns = $c['columns'];
        $this->sort = $c['sort'];
        $this->desc = $c['desc'];
        $this->name = $saved->name;
        $this->shared = $saved->shared;
        $this->saving = false;
        $this->resetErrorBag();
    }

    public function sortBy(string $column): void
    {
        abort_unless(isset(Reporting::REPORTS[$this->type]['columns'][$column]), 404);
        if ($this->sort === $column) {
            $this->desc ? $this->reset('sort', 'desc') : $this->desc = true;
        } else {
            [$this->sort, $this->desc] = [$column, false];
        }
    }

    /** Moves a shown column one place left or right. */
    public function move(string $column, int $by): void
    {
        $i = array_search($column, $this->columns, true);
        $j = $i === false ? false : $i + ($by < 0 ? -1 : 1);
        if ($i === false || $i === 0 || $j < 1 || $j >= count($this->columns)) {
            return; // the first column stays first: it says what each row is
        }
        [$this->columns[$i], $this->columns[$j]] = [$this->columns[$j], $this->columns[$i]];
    }

    public function resetColumns(): void
    {
        $this->columns = Reporting::defaultColumns($this->type);
        $this->reset('sort', 'desc');
    }

    public function startSave(bool $asNew = false): void
    {
        $this->resetErrorBag();
        if ($asNew) {
            $this->name = $this->savedId === '' ? Reporting::REPORTS[$this->type]['label'] : $this->name.' (copy)';
            $this->savedId = '';
            $this->shared = false;
        } elseif ($this->savedId === '') {
            $this->name = Reporting::REPORTS[$this->type]['label'];
        }
        $this->saving = true;
    }

    public function save(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:120']], [], ['name' => 'name']);
        $team = $this->team();
        $me = $this->me();
        $existing = $this->savedId === '' ? null : SavedReport::query()->where('team_id', $team->id)->find((int) $this->savedId);
        if ($existing !== null && ! $existing->manageableBy($me)) {
            $existing = null; // someone else's shared report: keep a copy of your own
        }
        $saved = $existing ?? new SavedReport;
        $saved->fill(['name' => trim($this->name), 'type' => $this->type, 'config' => $this->config(), 'shared' => $this->shared]);
        if (! $saved->exists) {
            $saved->forceFill(['team_id' => $team->id, 'user_id' => $me->id]);
        }
        $saved->save();
        $this->savedId = (string) $saved->id;
        $this->saving = false;
    }

    public function removeSaved(): void
    {
        $saved = SavedReport::query()->where('team_id', $this->team()->id)->find((int) $this->savedId);
        abort_if($saved === null, 404);
        abort_unless($saved->manageableBy($this->me()), 403);
        $saved->delete();
        $this->reset('savedId', 'saving', 'name', 'shared');
    }

    /** @return array{filters: array<string, mixed>, columns: list<string>, sort: string|null, desc: bool} */
    private function config(): array
    {
        return Reporting::normalise($this->type, [
            'filters' => ['scope' => $this->scope, 'portfolio' => $this->portfolio, 'projects' => $this->projects, 'period' => $this->period, 'from' => $this->from, 'to' => $this->to],
            'columns' => $this->columns,
            'sort' => $this->sort,
            'desc' => $this->desc,
        ]);
    }

    public function render(Reporting $reporting): View
    {
        $team = $this->team();
        $me = $this->me();
        $config = $this->config();
        $this->columns = $config['columns'];
        $result = $reporting->run($this->type, $team, $me, $config);
        $saved = SavedReport::query()->where('team_id', $team->id)->where(fn ($q) => $q->where('user_id', $me->id)->orWhere('shared', true))->with('user:id,name')->orderBy('name')->get();
        $current = $saved->firstWhere('id', (int) $this->savedId);
        $query = ['type' => $this->type, 'filters' => $config['filters'], 'columns' => $config['columns'], 'sort' => $config['sort'], 'desc' => $config['desc'] ? 1 : 0];

        return view('livewire.reporting.builder', [
            'def' => Reporting::REPORTS[$this->type],
            'config' => $config,
            'result' => $result,
            'about' => ReportExport::describe($this->type, $config['filters'], $result, $team),
            'saved' => $saved,
            'current' => $current,
            'canManage' => $current?->manageableBy($me) ?? false,
            'portfolios' => Portfolio::query()->where('team_id', $team->id)->orderBy('name')->get(['id', 'name']),
            'choosable' => Project::query()->where('team_id', $team->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'team_id', 'visibility', 'owner_id']),
            'exports' => collect(ReportExport::FORMATS)->mapWithKeys(fn ($label, $f) => [$f => $current && ! $this->dirty($current, $config)
                ? route('reporting.saved.export', [$current, $f])
                : route('reporting.export', ['format' => $f] + $query)])->all(),
            'dirty' => $current !== null && $this->dirty($current, $config),
        ]);
    }

    /** @param  array<string, mixed>  $config */
    private function dirty(SavedReport $saved, array $config): bool
    {
        return $saved->type !== $this->type || Reporting::normalise($saved->type, $saved->config) !== $config;
    }
}
