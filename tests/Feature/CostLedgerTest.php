<?php

namespace Tests\Feature;

use App\Livewire\Projects\ProjectMoney;
use App\Models\Activity;
use App\Models\BudgetRevision;
use App\Models\CostItem;
use App\Models\Project;
use App\Models\User;
use App\Services\ChangeControl;
use App\Services\CostLedger;
use App\Services\ProjectPeople;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P7a: the cost ledger. Budget lines with revisions that say why, commitments and actuals
 * (an actual may settle a commitment), cost to complete, forecast at completion, variance, budget
 * risk indicators, and a money permission: amounts are for the project's owner and managers and
 * the business owner and admins only, and never enter the shared activity log.
 */
class CostLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
    }

    /** @return array{boss: User, admin: User, pm: User, member: User, project: Project} */
    private function business(): array
    {
        $boss = User::factory()->withPersonalTeam()->create(['name' => 'Bongi Boss']);
        $team = $boss->currentTeam;
        $make = function (string $name, string $role) use ($team) {
            $u = User::factory()->create(['name' => $name, 'current_team_id' => $team->id]);
            $team->users()->attach($u, ['role' => $role]);

            return $u;
        };
        $admin = $make('Ayanda Admin', 'admin');
        $pm = $make('Palesa PM', 'editor');
        $member = $make('Mandla Member', 'editor');
        $project = Project::create(['team_id' => $team->id, 'owner_id' => $pm->id, 'name' => 'Plant shutdown', 'status' => 'active']);

        return ['boss' => $boss, 'admin' => $admin, 'pm' => $pm, 'member' => $member, 'project' => $project->fresh()];
    }

    private function refuses(callable $call, string $key): void
    {
        try {
            $call();
            $this->fail('expected a refusal on '.$key);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    public function test_the_ledger_adds_up_by_its_stated_formulas(): void
    {
        ['pm' => $pm, 'project' => $project] = $this->business();
        $this->actingAs($pm);
        $l = app(CostLedger::class);
        $materials = $l->addLine($project, $pm, 'materials', 'Steel and fixings', 25_000_000, null);
        $contractors = $l->addLine($project, $pm, 'contractors', 'Crane hire', 40_000_000, null);
        $contingency = $l->addLine($project, $pm, 'contingency', 'Contingency', 5_000_000, null);
        $cost = fn (array $d) => $l->addCost($project, $pm, $d + ['dated' => '2026-11-02', 'description' => 'Item']);

        $po = $cost(['budget_line_id' => $materials->id, 'kind' => 'commitment', 'amount_cents' => 10_000_000, 'reference' => 'PO-1']);
        $cost(['budget_line_id' => $materials->id, 'kind' => 'actual', 'amount_cents' => 6_000_000, 'settles_id' => $po->id]);
        $cost(['budget_line_id' => $materials->id, 'kind' => 'actual', 'amount_cents' => 3_000_000]);
        $cost(['budget_line_id' => $materials->id, 'kind' => 'actual', 'amount_cents' => -500_000, 'description' => 'Credit note']);
        $cost(['budget_line_id' => $contractors->id, 'kind' => 'actual', 'amount_cents' => 38_000_000]);
        $l->setToComplete($contractors->fresh(), 6_000_000);
        $cost(['budget_line_id' => $contingency->id, 'kind' => 'commitment', 'amount_cents' => 3_000_000]);

        $data = $l->ledger($project);
        $row = fn ($line) => collect($data['lines'])->first(fn ($r) => $r['line']->id === $line->id);
        $m = $row($materials);
        $this->assertSame([25_000_000, 8_500_000, 4_000_000, 12_500_000, 25_000_000, 0, 50], [$m['budget'], $m['actual'], $m['open'], $m['toComplete'], $m['forecast'], $m['variance'], $m['used']]);
        $c = $row($contractors);
        $this->assertSame([38_000_000, 0, 6_000_000, true, 44_000_000, -4_000_000], [$c['actual'], $c['open'], $c['toComplete'], $c['toCompleteSet'], $c['forecast'], $c['variance']]);
        $this->assertSame(60, $row($contingency)['used']);
        $this->assertSame([70_000_000, 46_500_000, 7_000_000, 74_000_000, -4_000_000], [$data['total']['budget'], $data['total']['actual'], $data['total']['open'], $data['total']['forecast'], $data['total']['variance']]);

        $project->tasks()->create(['title' => 'Strip', 'status' => 'todo', 'priority' => 'medium']);
        $risks = array_column($l->risks($project, $data), 'text');
        $this->assertContains('The forecast at completion is R 40 000 over the budget.', $risks);
        $this->assertContains('1 line forecast over budget: Crane hire.', $risks);
        $this->assertContains('Spending is ahead of progress: 66% of the budget is spent with 0% of tasks done.', $risks);
        $this->assertContains('60% of the contingency (Contingency) is used or committed.', $risks);

        $this->refuses(fn () => $l->removeCost($po), 'cost');
        $this->assertStringNotContainsString('25000000', Activity::query()->pluck('changes')->toJson(), 'amounts never enter the shared activity log');
    }

    public function test_revisions_say_why_and_link_to_approved_changes(): void
    {
        ['pm' => $pm, 'boss' => $boss, 'project' => $project] = $this->business();
        $this->actingAs($pm);
        $l = app(CostLedger::class);
        $line = $l->addLine($project, $pm, 'contractors', 'Crane hire', 40_000_000, null);
        $cc = app(ChangeControl::class);
        $change = $cc->addChange($project, $pm, ['title' => 'Standby crane', 'reason' => 'Clash with the kiln work', 'cost_cents' => 4_500_000]);
        $draft = $cc->addChange($project, $pm, ['title' => 'Not approved']);

        $this->refuses(fn () => $l->revise($line->fresh(), $pm, 45_000_000, '', null), 'reason');
        $this->refuses(fn () => $l->revise($line->fresh(), $pm, 45_000_000, 'Standby crane', $draft->id), 'change_id');
        $cc->submit($change, $pm, $boss->id);
        $this->actingAs($boss);
        $cc->decide($change->fresh(), $boss, true, null);
        $this->actingAs($pm);
        $this->assertContains('Approved change requests add R 45 000 to the cost, and budget revisions linked to them add R 0.', array_column($l->risks($project, $l->ledger($project)), 'text'));

        $l->revise($line->fresh(), $pm, 44_500_000, 'Standby crane for week 2', $change->id);
        $this->assertSame(44_500_000, $line->fresh()->budget_cents);
        $this->assertSame([2, 4_500_000], [BudgetRevision::query()->where('budget_line_id', $line->id)->count(), $l->ledger($project)['revised']]);
        $this->assertNotContains('Approved change requests add R 45 000 to the cost, and budget revisions linked to them add R 45 000.', array_column($l->risks($project, $l->ledger($project)), 'text'));

        Livewire::actingAs($pm)->test(ProjectMoney::class, ['project' => $project])->set('openLine', (string) $line->id)
            ->assertSee('R 400 000 to R 445 000')->assertSee('Standby crane for week 2 (CR-01)')->assertSee('Original budget');
    }

    public function test_costs_are_checked_before_they_are_recorded(): void
    {
        ['pm' => $pm, 'project' => $project] = $this->business();
        $this->actingAs($pm);
        $l = app(CostLedger::class);
        $a = $l->addLine($project, $pm, 'materials', 'Steel', 1_000_000, null);
        $b = $l->addLine($project, $pm, 'equipment', 'Hire', 1_000_000, null);
        $po = $l->addCost($project, $pm, ['budget_line_id' => $a->id, 'kind' => 'commitment', 'description' => 'Order', 'amount_cents' => 50_000, 'dated' => '2026-11-01']);
        $other = Project::create(['team_id' => $project->team_id, 'owner_id' => $pm->id, 'name' => 'Other', 'status' => 'active']);
        $foreign = $l->addLine($other, $pm, 'other', 'Elsewhere', 0, null);
        $base = ['budget_line_id' => $a->id, 'kind' => 'actual', 'description' => 'Invoice', 'amount_cents' => 10_000, 'dated' => '2026-11-03'];

        $this->refuses(fn () => $l->addCost($project, $pm, ['dated' => '2026-11-10'] + $base), 'dated');
        $this->refuses(fn () => $l->addCost($project, $pm, ['amount_cents' => 0] + $base), 'amount_cents');
        $this->refuses(fn () => $l->addCost($project, $pm, ['kind' => 'commitment', 'amount_cents' => -5] + $base), 'amount_cents');
        $this->refuses(fn () => $l->addCost($project, $pm, ['budget_line_id' => $foreign->id] + $base), 'budget_line_id');
        $this->refuses(fn () => $l->addCost($project, $pm, ['budget_line_id' => $b->id, 'settles_id' => $po->id] + $base), 'settles_id');
        $this->refuses(fn () => $l->addCost($project, $pm, ['description' => '  '] + $base), 'description');
        $this->assertSame(1, CostItem::query()->where('project_id', $project->id)->count());

        Livewire::actingAs($pm)->test(ProjectMoney::class, ['project' => $project])->set('costLine', (string) $a->id)->set('costKind', 'actual')
            ->set('description', 'Invoice 77')->set('supplier', 'Macsteel')->set('reference', 'INV-77')->set('amount', 'R12 500,50')->set('settles', (string) $po->id)->call('addCost')->assertHasNoErrors();
        $this->assertSame([1_250_050, $po->id], [CostItem::query()->where('reference', 'INV-77')->value('amount_cents'), CostItem::query()->where('reference', 'INV-77')->value('settles_id')]);
    }

    public function test_money_is_seen_only_by_those_who_run_the_project(): void
    {
        ['boss' => $boss, 'admin' => $admin, 'pm' => $pm, 'member' => $member, 'project' => $project] = $this->business();
        $this->actingAs($pm);
        app(CostLedger::class)->addLine($project, $pm, 'materials', 'Steel', 25_000_000, null);

        $this->actingAs($pm)->get(route('projects.money', $project))->assertOk()->assertSee('Cost report')->assertSee('R 250 000');
        $this->actingAs($pm)->get(route('projects.show', $project))->assertSee('Cost forecast')->assertSee('>Money<', false);
        $this->actingAs($boss)->get(route('projects.money', $project))->assertOk();
        $this->actingAs($admin)->get(route('projects.money', $project))->assertOk();

        $this->actingAs($member)->get(route('projects.money', $project))->assertForbidden();
        $this->actingAs($member)->get(route('projects.show', $project))->assertOk()->assertDontSee('Cost forecast')->assertDontSee('>Money<', false);
        Livewire::actingAs($member)->test(ProjectMoney::class, ['project' => $project])->assertForbidden();

        app(ProjectPeople::class)->add($project, $pm, $member->id, 'manager');
        $this->actingAs($member)->get(route('projects.money', $project))->assertOk();
    }
}
