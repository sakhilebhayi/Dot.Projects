<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\PortfolioOverview;
use App\Services\Workload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * R-34 P12c: the load test's findings kept fixed. The workload of a business with thousands of open tasks once took
 * seconds because every task was looked up by scanning the whole list; and the portfolio's cost per project must
 * not grow with the number of tasks in it (one query per kind of record, never one per task).
 */
class PerformanceBudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-07 09:00:00');
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->actingAs($this->owner);
    }

    private function project(int $tasks): Project
    {
        $p = Project::create(['team_id' => $this->owner->currentTeam->id, 'owner_id' => $this->owner->id, 'name' => 'P', 'status' => 'active', 'start_date' => '2026-11-01', 'due_date' => '2027-03-01']);
        $rows = [];
        for ($t = 1; $t <= $tasks; $t++) {
            $rows[] = ['project_id' => $p->id, 'assignee_id' => $this->owner->id, 'title' => "T{$t}", 'status' => $t % 3 ? 'todo' : 'done', 'priority' => 'medium',
                'start_date' => Carbon::today()->subDays($t % 20)->toDateString(), 'due_date' => Carbon::today()->addDays($t % 40)->toDateString(), 'estimate_minutes' => 240, 'sort_order' => $t];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('project_tasks')->insert($chunk);
        }
        $p->budgetLines()->forceCreate(['category' => 'materials', 'name' => 'Materials', 'budget_cents' => 1_000_000]);

        return $p;
    }

    /** Queries for one cold run: nothing kept from an earlier one (R-34 P13e keeps project facts between runs). */
    private function queries(callable $f): int
    {
        Cache::flush();
        app()->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $f();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_the_workload_of_thousands_of_open_tasks_is_quick(): void
    {
        $team = $this->owner->currentTeam;
        foreach (range(1, 6) as $i) {
            $this->project(500);
        }
        $start = microtime(true);
        $rows = app(Workload::class)->forPeople($team, $team->allUsers(), $this->owner);
        $seconds = microtime(true) - $start;

        $this->assertGreaterThan(0, collect($rows[0]['weeks'])->sum(fn ($w) => count($w['items'])), 'the open tasks were planned into weeks');
        // Scanning the whole list for every task took ~1.6 s for 2,000 tasks and grows with the square; indexed it takes ~0.1 s.
        $this->assertLessThan(1.5, $seconds, 'Workload::forPeople took '.round($seconds, 2).' s for 2,000 open tasks');
    }

    public function test_the_portfolio_s_queries_do_not_grow_with_tasks(): void
    {
        $small = collect([$this->project(5), $this->project(5)]);
        $big = collect([$this->project(200), $this->project(200)]);
        $overview = app(PortfolioOverview::class);

        $q = fn ($projects) => $this->queries(fn () => $overview->build($projects->map->fresh(), $this->owner));
        $this->assertSame($q($small), $q($big), 'the portfolio makes more queries when projects have more tasks');
    }

    public function test_the_portfolio_s_queries_per_project_stay_within_budget(): void
    {
        $overview = app(PortfolioOverview::class);
        $two = collect([$this->project(10), $this->project(10)]);
        $six = $two->merge([$this->project(10), $this->project(10), $this->project(10), $this->project(10)]);

        $perProject = ($this->queries(fn () => $overview->build($six->map->fresh(), $this->owner)) - $this->queries(fn () => $overview->build($two->map->fresh(), $this->owner))) / 4;
        // 2026-12-07: 33 per project (one per kind of record the health, money and schedule facts read).
        $this->assertLessThanOrEqual(36, $perProject);
    }
}
