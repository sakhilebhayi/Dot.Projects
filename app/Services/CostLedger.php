<?php

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\BudgetRevision;
use App\Models\CostItem;
use App\Models\Project;
use App\Models\ProjectChange;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The project's budget and costs, and the figures worked out from them. Formulas, per line and in
 * total (amounts in cents):
 *
 *   actual           = sum of actual costs
 *   open commitments = for each commitment, its amount less the actuals that settle it (never < 0)
 *   cost to complete = the figure someone set, or else budget - actual - open commitments (never < 0)
 *   forecast         = actual + open commitments + cost to complete  (forecast at completion)
 *   variance         = budget - forecast  (negative means over budget)
 *   used             = (actual + open commitments) / budget
 *
 * The activity log, which every project member can read, records these changes without amounts.
 */
final class CostLedger
{
    public function addLine(Project $project, User $by, string $category, string $name, int $budgetCents, ?string $reason): BudgetLine
    {
        $this->assertCategory($category);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw ValidationException::withMessages(['name' => 'Name the line in up to 160 characters.']);
        }
        $this->assertAmount($budgetCents, 'budget', false);

        return DB::transaction(function () use ($project, $by, $category, $name, $budgetCents, $reason) {
            $line = new BudgetLine(['category' => $category, 'name' => $name, 'sort_order' => (int) $project->budgetLines()->max('sort_order') + 1]);
            $line->forceFill(['project_id' => $project->id, 'budget_cents' => 0])->save();
            if ($budgetCents !== 0) {
                $this->writeRevision($line, $by, $budgetCents, trim((string) $reason) !== '' ? trim((string) $reason) : 'Original budget', null);
            }
            Activity::record('budget.line_added', $project, ['category' => $category]);

            return $line;
        });
    }

    /** Changes a line's amount, always with a reason; a revision that comes from an approved change says which. */
    public function revise(BudgetLine $line, User $by, int $toCents, string $reason, ?int $changeId): void
    {
        $this->assertAmount($toCents, 'budget', false);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Say why the budget changes, in up to 500 characters.']);
        }
        if ($changeId !== null && ! ProjectChange::query()->where('project_id', $line->project_id)->whereIn('status', ['approved', 'implemented'])->whereKey($changeId)->exists()) {
            throw ValidationException::withMessages(['change_id' => 'Choose an approved change request of this project.']);
        }
        if ($toCents === $line->budget_cents) {
            throw ValidationException::withMessages(['budget' => 'That is the amount already budgeted.']);
        }
        DB::transaction(fn () => $this->writeRevision($line, $by, $toCents, $reason, $changeId));
        Activity::record('budget.revised', $line->project, ['change_id' => $changeId]);
    }

    public function rename(BudgetLine $line, string $category, string $name): void
    {
        $this->assertCategory($category);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw ValidationException::withMessages(['name' => 'Name the line in up to 160 characters.']);
        }
        $line->fill(['category' => $category, 'name' => $name])->save();
    }

    /** Sets (or, with null, clears) the cost still to come on a line. */
    public function setToComplete(BudgetLine $line, ?int $cents): void
    {
        if ($cents !== null) {
            $this->assertAmount($cents, 'to_complete', false);
        }
        $line->forceFill(['to_complete_cents' => $cents])->save();
        Activity::record('budget.forecast_set', $line->project, []);
    }

    /**
     * Records a commitment or an actual. An actual may settle a commitment on the same line.
     *
     * @param  array{budget_line_id: int|null, kind: string, description: string, supplier?: string|null, reference?: string|null, amount_cents: int|null, dated: string|null, settles_id?: int|null}  $data
     */
    public function addCost(Project $project, User $by, array $data): CostItem
    {
        $line = BudgetLine::query()->where('project_id', $project->id)->find($data['budget_line_id']);
        if ($line === null) {
            throw ValidationException::withMessages(['budget_line_id' => 'Choose a line of this budget.']);
        }
        if (! array_key_exists($data['kind'], CostItem::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Choose commitment or actual.']);
        }
        $description = trim($data['description']);
        if ($description === '' || mb_strlen($description) > 255) {
            throw ValidationException::withMessages(['description' => 'Say what it is for, in up to 255 characters.']);
        }
        $this->assertAmount($data['amount_cents'], 'amount_cents', true);
        if ($data['kind'] === 'commitment' && $data['amount_cents'] < 0) {
            throw ValidationException::withMessages(['amount_cents' => 'A commitment is a positive amount; record a credit note as a negative actual.']);
        }
        try {
            $dated = Carbon::parse((string) $data['dated'])->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['dated' => 'Give the date of the order or invoice.']);
        }
        if ($dated->gt(Carbon::today())) {
            throw ValidationException::withMessages(['dated' => 'Record costs once they are ordered or incurred, not ahead of time.']);
        }
        $settles = null;
        if (! empty($data['settles_id'])) {
            $settles = CostItem::query()->where('budget_line_id', $line->id)->where('kind', 'commitment')->find($data['settles_id']);
            if ($data['kind'] !== 'actual' || $settles === null) {
                throw ValidationException::withMessages(['settles_id' => 'Only an actual settles a commitment, on the same line.']);
            }
        }
        $t = fn ($v, int $max) => ($s = trim((string) $v)) === '' ? null : mb_substr($s, 0, $max);
        $item = new CostItem(['budget_line_id' => $line->id, 'kind' => $data['kind'], 'description' => $description, 'supplier' => $t($data['supplier'] ?? null, 160), 'reference' => $t($data['reference'] ?? null, 80), 'amount_cents' => $data['amount_cents'], 'dated' => $dated, 'settles_id' => $settles?->id]);
        $item->forceFill(['project_id' => $project->id, 'created_by' => $by->id])->save();
        Activity::record('cost.'.$data['kind'].'_added', $project, ['category' => $line->category]);

        return $item;
    }

    public function removeCost(CostItem $item): void
    {
        if ($item->kind === 'commitment' && $item->settledBy()->exists()) {
            throw ValidationException::withMessages(['cost' => 'Invoices settle this commitment; remove those first.']);
        }
        $project = Project::withoutTeamScope(fn () => Project::query()->find($item->project_id));
        $kind = $item->kind;
        $item->delete();
        if ($project) {
            Activity::record('cost.'.$kind.'_removed', $project, []);
        }
    }

    /**
     * The ledger: one row per line and the totals, with the formulas in the class docblock.
     *
     * @return array{lines: list<array{line: BudgetLine, budget: int, actual: int, open: int, toComplete: int, toCompleteSet: bool, forecast: int, variance: int, used: int|null}>, total: array{budget: int, actual: int, open: int, toComplete: int, forecast: int, variance: int, used: int|null}, revised: int}
     */
    public function ledger(Project $project): array
    {
        $lines = $project->budgetLines()->get();
        $items = $project->costItems()->get();
        $actualByLine = $items->where('kind', 'actual')->groupBy('budget_line_id')->map(fn (Collection $g) => (int) $g->sum('amount_cents'));
        $settled = $items->where('kind', 'actual')->whereNotNull('settles_id')->groupBy('settles_id')->map(fn (Collection $g) => (int) $g->sum('amount_cents'));
        $openByLine = $items->where('kind', 'commitment')->groupBy('budget_line_id')
            ->map(fn (Collection $g) => (int) $g->sum(fn (CostItem $c) => max(0, $c->amount_cents - (int) ($settled[$c->id] ?? 0))));

        $rows = [];
        foreach ($lines as $line) {
            $actual = (int) ($actualByLine[$line->id] ?? 0);
            $open = (int) ($openByLine[$line->id] ?? 0);
            $toComplete = $line->to_complete_cents ?? max(0, $line->budget_cents - $actual - $open);
            $forecast = $actual + $open + $toComplete;
            $rows[] = ['line' => $line, 'budget' => $line->budget_cents, 'actual' => $actual, 'open' => $open, 'toComplete' => $toComplete, 'toCompleteSet' => $line->to_complete_cents !== null,
                'forecast' => $forecast, 'variance' => $line->budget_cents - $forecast, 'used' => $line->budget_cents > 0 ? (int) round(($actual + $open) / $line->budget_cents * 100) : null];
        }
        $sum = fn (string $k) => (int) array_sum(array_column($rows, $k));
        $budget = $sum('budget');
        $firstRevisions = BudgetRevision::query()->whereIn('budget_line_id', $lines->pluck('id'))->selectRaw('budget_line_id, min(id) as first')->groupBy('budget_line_id')->pluck('first');
        $revised = (int) BudgetRevision::query()->whereIn('budget_line_id', $lines->pluck('id'))->whereNotIn('id', $firstRevisions)->get()->sum(fn ($r) => $r->to_cents - $r->from_cents);

        return [
            'lines' => $rows,
            'total' => ['budget' => $budget, 'actual' => $sum('actual'), 'open' => $sum('open'), 'toComplete' => $sum('toComplete'), 'forecast' => $sum('forecast'), 'variance' => $budget - $sum('forecast'), 'used' => $budget > 0 ? (int) round(($sum('actual') + $sum('open')) / $budget * 100) : null],
            'revised' => $revised,
        ];
    }

    /**
     * Budget risk indicators, each a sentence with the facts behind it: forecast over budget, lines
     * over, spending ahead of progress, contingency drawn, approved changes the budget does not show.
     *
     * @param  array<string, mixed>  $ledger
     * @return list<array{level: string, text: string}>
     */
    public function risks(Project $project, array $ledger): array
    {
        $t = $ledger['total'];
        $out = [];
        if ($t['budget'] > 0 && $t['variance'] < 0) {
            $out[] = ['level' => 'negative', 'text' => 'The forecast at completion is '.Money::rand(-$t['variance']).' over the budget.'];
        }
        $over = array_filter($ledger['lines'], fn ($r) => $r['variance'] < 0 && $r['line']->category !== 'contingency');
        if (count($over) > 0) {
            $out[] = ['level' => 'negative', 'text' => count($over).' '.str('line')->plural(count($over)).' forecast over budget: '.implode(', ', array_map(fn ($r) => $r['line']->name, $over)).'.'];
        }
        $tasks = $project->tasks()->count();
        if ($t['budget'] > 0 && $tasks > 0) {
            $spent = $t['actual'] / $t['budget'];
            $done = $project->tasks()->where('status', 'done')->count() / $tasks;
            if ($spent - $done > 0.15) {
                $out[] = ['level' => 'warning', 'text' => 'Spending is ahead of progress: '.(int) round($spent * 100).'% of the budget is spent with '.(int) round($done * 100).'% of tasks done.'];
            }
        }
        foreach (array_filter($ledger['lines'], fn ($r) => $r['line']->category === 'contingency' && $r['used'] !== null && $r['used'] >= 50) as $r) {
            $out[] = ['level' => 'warning', 'text' => $r['used'].'% of the contingency ('.$r['line']->name.') is used or committed.'];
        }
        $approved = (int) $project->changes()->whereIn('status', ['approved', 'implemented'])->sum('cost_cents');
        $reflected = (int) BudgetRevision::query()->whereIn('budget_line_id', $project->budgetLines()->pluck('id'))->whereNotNull('change_id')->get()->sum(fn ($r) => $r->to_cents - $r->from_cents);
        if ($approved !== $reflected && $approved !== 0) {
            $out[] = ['level' => 'warning', 'text' => 'Approved change requests add '.Money::rand($approved).' to the cost, and budget revisions linked to them add '.Money::rand($reflected).'.'];
        }

        return $out;
    }

    private function writeRevision(BudgetLine $line, User $by, int $toCents, string $reason, ?int $changeId): void
    {
        $r = new BudgetRevision(['from_cents' => $line->budget_cents, 'to_cents' => $toCents, 'reason' => mb_substr($reason, 0, 500), 'change_id' => $changeId]);
        $r->forceFill(['budget_line_id' => $line->id, 'created_by' => $by->id])->save();
        $line->forceFill(['budget_cents' => $toCents])->save();
    }

    private function assertCategory(string $category): void
    {
        if (! array_key_exists($category, BudgetLine::CATEGORIES)) {
            throw ValidationException::withMessages(['category' => 'Choose a category.']);
        }
    }

    private function assertAmount(mixed $cents, string $key, bool $allowNegative): void
    {
        $limit = 100_000_000_000; // R 1 billion
        if (! is_int($cents) || abs($cents) > $limit || (! $allowNegative && $cents < 0) || ($allowNegative && $cents === 0)) {
            throw ValidationException::withMessages([$key => $allowNegative ? 'Give the amount in rand; a credit note is negative.' : 'Give the amount in rand, from 0 up to R 1 billion.']);
        }
    }
}
