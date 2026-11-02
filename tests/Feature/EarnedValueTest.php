<?php

namespace Tests\Feature;

use App\Livewire\Projects\ProjectMoney;
use App\Livewire\Workload\Board;
use App\Models\Project;
use App\Models\User;
use App\Services\CostLedger;
use App\Services\EarnedValue;
use App\Services\LabourCost;
use App\Services\ProjectWork;
use App\Services\TimeTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P7b: earned value by the stated method (weights by estimate, PV from the baseline, EV by
 * the 0/50/100 rule) and labour cost from approved time at dated hourly rates, seen and set only
 * by the business owner and admins.
 */
class EarnedValueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00'); // a Wednesday
    }

    /** @return array{boss: User, pm: User, worker: User, project: Project} */
    private function business(): array
    {
        $boss = User::factory()->withPersonalTeam()->create(['name' => 'Bongi Boss']);
        $team = $boss->currentTeam;
        $make = function (string $name) use ($team) {
            $u = User::factory()->create(['name' => $name, 'current_team_id' => $team->id]);
            $team->users()->attach($u, ['role' => 'editor']);

            return $u;
        };
        $pm = $make('Palesa PM');
        $worker = $make('Wandile Worker');
        $project = Project::create(['team_id' => $team->id, 'owner_id' => $pm->id, 'name' => 'Plant shutdown', 'status' => 'active']);

        return ['boss' => $boss, 'pm' => $pm, 'worker' => $worker, 'project' => $project->fresh()];
    }

    public function test_earned_value_follows_the_stated_method(): void
    {
        ['pm' => $pm, 'project' => $project] = $this->business();
        $this->actingAs($pm);
        $ledger = app(CostLedger::class);
        $line = $ledger->addLine($project, $pm, 'contractors', 'Main contract', 10_000_000, null);
        $ledger->addCost($project, $pm, ['budget_line_id' => $line->id, 'kind' => 'actual', 'description' => 'Invoice', 'amount_cents' => 3_000_000, 'dated' => '2026-11-03']);
        $t = fn (array $a) => $project->tasks()->create($a + ['priority' => 'medium']);
        $a = $t(['title' => 'A', 'status' => 'done', 'estimate_minutes' => 600, 'start_date' => '2026-11-02', 'due_date' => '2026-11-06']);
        $t(['title' => 'B', 'status' => 'in_progress', 'estimate_minutes' => 600, 'start_date' => '2026-11-09', 'due_date' => '2026-11-13']);
        $t(['title' => 'C', 'status' => 'todo', 'estimate_minutes' => null, 'due_date' => '2026-10-30']);
        $t(['title' => 'D', 'status' => 'todo', 'estimate_minutes' => 1200]);
        $parent = $t(['title' => 'E', 'status' => 'todo', 'estimate_minutes' => 9000, 'due_date' => '2026-11-04']);
        $t(['title' => 'F', 'status' => 'todo', 'estimate_minutes' => 600, 'start_date' => '2026-11-04', 'due_date' => '2026-11-04', 'parent_id' => $parent->id]);

        $ev = app(EarnedValue::class)->compute($project);
        // Weights: A 600, B 600, C 750 (the average of 600, 600, 1200, 600), D 1200, F 600 = 3750;
        // E has a subtask, so only F counts.
        // PV share: A 3 of 5 days (360) + C past due (750) + F due today (600) = 1710 of 3750.
        // EV share: A done (600) + B started at 50% (300) = 900 of 3750.
        $this->assertSame([10_000_000, 4_560_000, 2_400_000, 3_000_000], [$ev['bac'], $ev['pv'], $ev['ev'], $ev['ac']]);
        $this->assertSame([-2_160_000, -600_000, 0.53, 0.8], [$ev['sv'], $ev['cv'], $ev['spi'], $ev['cpi']]);
        $this->assertSame([12_500_000, 9_500_000, -2_500_000, 1.09], [$ev['eac'], $ev['etc'], $ev['vac'], $ev['tcpi']]);
        $this->assertSame([5, 4, 68, true, null], [$ev['tasks'], $ev['dated'], $ev['coverage'], $ev['weighted'], $ev['baseline']]);

        app(ProjectWork::class)->setBaseline($project, $pm, 'Agreed plan');
        app(ProjectWork::class)->reschedule($a->fresh(), '2026-11-04', '2026-11-04');
        $this->assertSame(4_560_000, app(EarnedValue::class)->compute($project->fresh())['pv'], 'PV follows the baseline, not later moves');
        $this->assertSame(1, app(EarnedValue::class)->compute($project->fresh())['baseline']);

        $this->assertSame(0, app(EarnedValue::class)->compute($project->fresh(), Carbon::parse('2026-10-01'))['pv'], 'nothing planned before the work starts');

        Livewire::actingAs($pm)->test(ProjectMoney::class, ['project' => $project->fresh()])
            ->assertSee('For each rand spent, the project has earned 80c of planned work (CPI 0.80).')
            ->assertSee('Planned dates come from baseline 1.');
    }

    public function test_approved_time_is_costed_at_the_rate_on_the_day(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'worker' => $worker, 'project' => $project] = $this->business();
        $labour = app(LabourCost::class);
        $this->actingAs($boss);
        $labour->setRate($project->team, $boss, $worker->id, 50_000, '2026-10-01');
        $labour->setRate($project->team, $boss, $worker->id, 60_000, '2026-11-03');
        try {
            $labour->setRate($project->team, $pm, $worker->id, 1, '2026-11-01');
            $this->fail('an editor set a rate');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rate', $e->errors());
        }

        $time = app(TimeTracking::class);
        $this->actingAs($worker);
        $time->log($worker, $project, ['work_date' => '2026-11-02', 'minutes' => 120]);
        $time->log($worker, $project, ['work_date' => '2026-11-03', 'minutes' => 60]);
        $this->actingAs($pm);
        $time->log($pm, $project, ['work_date' => '2026-11-03', 'minutes' => 60]);
        $this->assertSame(0, $labour->forProject($project)['cents'], 'only approved weeks count');

        foreach ([$worker, $pm] as $person) {
            $this->actingAs($person);
            $sheet = $time->sheet($person, $project->team, Carbon::parse('2026-11-02'));
            $time->submit($sheet, $person, $boss->id);
            $this->actingAs($boss);
            $time->approve($sheet->fresh(), $boss);
        }
        $cost = $labour->forProject($project);
        $this->assertSame([160_000, 240, 60], [$cost['cents'], $cost['minutes'], $cost['unpriced']], '2h at R500 + 1h at R600; the PM has no rate');

        $this->actingAs($pm);
        $line = app(CostLedger::class)->addLine($project, $pm, 'labour', 'Crew', 1_000_000, null);
        Livewire::actingAs($pm)->test(ProjectMoney::class, ['project' => $project])->call('chargeTime', (string) $line->id)->assertHasNoErrors();
        $row = app(CostLedger::class)->ledger($project->fresh())['lines'][0];
        $this->assertSame([160_000, 160_000], [$row['actual'], $row['fromTime']]);

        Livewire::actingAs($pm)->test(ProjectMoney::class, ['project' => $project->fresh()])->assertSee('R 1 600')->assertSee('1h not costed')->assertDontSee('Wandile Worker');
        Livewire::actingAs($boss)->test(ProjectMoney::class, ['project' => $project->fresh()])->assertSee('Wandile Worker');
        Livewire::actingAs($boss)->test(Board::class)->assertSee('R 600 an hour');
        Livewire::actingAs($pm)->test(Board::class)->assertDontSee('an hour')->call('editRate', $worker->id)->assertForbidden();
    }
}
