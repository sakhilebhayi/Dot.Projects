<?php

namespace App\Livewire\Projects;

use App\Models\BudgetLine;
use App\Models\CostItem;
use App\Models\Project;
use App\Services\CostLedger;
use App\Services\EarnedValue;
use App\Services\LabourCost;
use App\Services\Workload;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The project's cost ledger: budget lines with what is spent, committed, still to come and the
 * forecast against budget; revising a line with a reason; recording commitments and actuals; the
 * budget risk indicators. Only those who may see the project's money reach it.
 */
class ProjectMoney extends Component
{
    public Project $project;

    #[Url(as: 'line', except: '')]
    public string $openLine = '';

    #[Url(as: 'kind', except: '')]
    public string $kindFilter = '';

    #[Url(as: 'asof', except: '')]
    public string $asOf = '';

    public string $newCategory = 'materials';

    public string $newName = '';

    public string $newAmount = '';

    public ?int $revising = null;

    public string $reviseTo = '';

    public string $reviseReason = '';

    public string $reviseChange = '';

    public ?int $forecasting = null;

    public string $forecastAmount = '';

    public string $costLine = '';

    public string $costKind = 'actual';

    public string $description = '';

    public string $supplier = '';

    public string $reference = '';

    public string $amount = '';

    public string $dated = '';

    public string $settles = '';

    public function mount(Project $project): void
    {
        $this->authorize('seeMoney', $project);
        $this->project = $project;
        $this->dated = Carbon::today()->toDateString();
        $this->costLine = (string) ($project->budgetLines()->value('id') ?? '');
    }

    private function cents(string $text, string $key, bool $required = true): ?int
    {
        if (! $required && trim($text) === '') {
            return null;
        }
        $c = Money::parse($text);
        if ($c === null) {
            throw ValidationException::withMessages([$key => 'Give the amount in rand, such as 45 000 or 1 250,50.']);
        }

        return $c;
    }

    private function line(int $id): BudgetLine
    {
        $line = BudgetLine::query()->where('project_id', $this->project->id)->find($id);
        abort_if($line === null, 404);

        return $line;
    }

    public function addLine(CostLedger $ledger): void
    {
        $this->authorize('editMoney', $this->project);
        $this->resetErrorBag();
        $line = $ledger->addLine($this->project, auth()->user(), $this->newCategory, $this->newName, (int) $this->cents($this->newAmount === '' ? '0' : $this->newAmount, 'budget'), null);
        $this->reset('newName', 'newAmount');
        $this->costLine = $this->costLine ?: (string) $line->id;
    }

    public function startRevise(int $id): void
    {
        $this->authorize('editMoney', $this->project);
        $line = $this->line($id);
        $this->revising = $line->id;
        $this->reviseTo = rtrim(rtrim(number_format($line->budget_cents / 100, 2, '.', ''), '0'), '.');
        $this->reset('reviseReason', 'reviseChange');
        $this->resetErrorBag();
    }

    public function revise(CostLedger $ledger): void
    {
        $this->authorize('editMoney', $this->project);
        $this->resetErrorBag();
        abort_if($this->revising === null, 404);
        $ledger->revise($this->line($this->revising), auth()->user(), (int) $this->cents($this->reviseTo, 'budget'), $this->reviseReason, $this->reviseChange === '' ? null : (int) $this->reviseChange);
        $this->reset('revising', 'reviseTo', 'reviseReason', 'reviseChange');
    }

    public function startForecast(int $id): void
    {
        $this->authorize('editMoney', $this->project);
        $line = $this->line($id);
        $this->forecasting = $line->id;
        $this->forecastAmount = $line->to_complete_cents === null ? '' : rtrim(rtrim(number_format($line->to_complete_cents / 100, 2, '.', ''), '0'), '.');
        $this->resetErrorBag();
    }

    public function saveForecast(CostLedger $ledger): void
    {
        $this->authorize('editMoney', $this->project);
        $this->resetErrorBag();
        abort_if($this->forecasting === null, 404);
        $ledger->setToComplete($this->line($this->forecasting), $this->cents($this->forecastAmount, 'to_complete', false));
        $this->reset('forecasting', 'forecastAmount');
    }

    public function addCost(CostLedger $ledger): void
    {
        $this->authorize('editMoney', $this->project);
        $this->resetErrorBag();
        $ledger->addCost($this->project, auth()->user(), [
            'budget_line_id' => $this->costLine === '' ? null : (int) $this->costLine,
            'kind' => $this->costKind,
            'description' => $this->description,
            'supplier' => $this->supplier,
            'reference' => $this->reference,
            'amount_cents' => $this->cents($this->amount, 'amount_cents'),
            'dated' => $this->dated,
            'settles_id' => $this->costKind === 'actual' && $this->settles !== '' ? (int) $this->settles : null,
        ]);
        $this->reset('description', 'supplier', 'reference', 'amount', 'settles');
    }

    public function chargeTime(string $lineId, CostLedger $ledger): void
    {
        $this->authorize('editMoney', $this->project);
        $this->resetErrorBag();
        $ledger->chargeTimeTo($this->project, $lineId === '' ? null : (int) $lineId);
        $this->project->refresh();
    }

    public function removeCost(int $id, CostLedger $ledger): void
    {
        $this->authorize('editMoney', $this->project);
        $this->resetErrorBag();
        $item = CostItem::query()->where('project_id', $this->project->id)->find($id);
        abort_if($item === null, 404);
        $ledger->removeCost($item);
    }

    public function render(): View
    {
        $ledger = app(CostLedger::class);
        $data = $ledger->ledger($this->project);
        $items = $this->project->costItems()->with(['line:id,name', 'settles:id,description,reference', 'creator:id,name'])
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
            ->when($this->openLine !== '', fn ($q) => $q->where('budget_line_id', (int) $this->openLine))
            ->orderByDesc('dated')->orderByDesc('id')->get();

        try {
            $asOf = $this->asOf === '' ? Carbon::today() : Carbon::parse($this->asOf);
        } catch (\Throwable) {
            $asOf = Carbon::today();
        }

        return view('livewire.projects.project-money', [
            'data' => $data,
            'ev' => app(EarnedValue::class)->compute($this->project, $asOf),
            'labour' => app(LabourCost::class)->forProject($this->project),
            'seesRates' => Workload::oversees(auth()->user(), $this->project->team),
            'risks' => $ledger->risks($this->project, $data),
            'items' => $items,
            'openCommitments' => $this->costLine === '' ? collect() : CostItem::query()->where('budget_line_id', (int) $this->costLine)->where('kind', 'commitment')->orderByDesc('dated')->get(['id', 'description', 'reference', 'amount_cents']),
            'changes' => $this->project->changes()->whereIn('status', ['approved', 'implemented'])->orderBy('number')->get(['id', 'number', 'title', 'cost_cents']),
            'history' => $this->openLine === '' ? collect() : $this->line((int) $this->openLine)->revisions()->with(['creator:id,name', 'change:id,number,title'])->get(),
            'canEdit' => auth()->user()->can('editMoney', $this->project),
        ]);
    }
}
