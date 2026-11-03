<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\CostLedger;
use App\Services\LabourCost;
use App\Services\PortfolioOverview;
use App\Services\ProjectFacts;
use App\Services\ProjectWork;
use App\Services\Reporting;
use App\Services\StatusReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * R-34 P13e: the portfolio and reports keep each project's facts until anything they are read from changes. Kept
 * facts must always equal facts worked out fresh, and every kind of change must show at once.
 */
class ProjectFactsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $kiln;

    private Project $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
        Cache::flush();
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->actingAs($this->owner);
        $this->kiln = $this->project('Kiln');
        $this->clinic = $this->project('Clinic');
    }

    private function project(string $name): Project
    {
        $p = Project::create(['team_id' => $this->owner->currentTeam->id, 'owner_id' => $this->owner->id, 'name' => $name, 'status' => 'active', 'start_date' => '2026-11-02', 'due_date' => '2026-12-18']);
        $a = $p->tasks()->create(['title' => $name.' A', 'status' => 'todo', 'priority' => 'medium', 'start_date' => '2026-11-02', 'due_date' => '2026-11-10', 'estimate_minutes' => 600]);
        $b = $p->tasks()->create(['title' => $name.' B', 'status' => 'todo', 'priority' => 'medium', 'start_date' => '2026-11-11', 'due_date' => '2026-12-18', 'estimate_minutes' => 600]);
        app(ProjectWork::class)->addDependency($b, $a, $this->owner);
        app(CostLedger::class)->addLine($p, $this->owner, 'materials', 'Materials', 1_000_000, null);

        return $p;
    }

    /** As a new request would see it: no stamps carried over. */
    private function facts(Project $p): array
    {
        app()->forgetScopedInstances();

        return app(ProjectFacts::class)->facts($p->fresh(), Carbon::today()->subDays(6), Carbon::today());
    }

    private function money(Project $p): ?array
    {
        app()->forgetScopedInstances();

        return app(ProjectFacts::class)->money($p->fresh());
    }

    public function test_kept_facts_equal_fresh_ones_and_the_pages_read_the_same_cold_or_warm(): void
    {
        $fresh = app(StatusReports::class)->facts($this->kiln->fresh(), Carbon::today()->subDays(6), Carbon::today());
        $this->assertSame($fresh, $this->facts($this->kiln));
        $this->assertSame($fresh, $this->facts($this->kiln), 'the second read comes from the cache and is identical');

        $build = fn () => (app()->forgetScopedInstances() ?: app(PortfolioOverview::class)->build(Project::query()->get(), $this->owner));
        $cold = $build();
        $this->assertEquals($cold['rows'], $build()['rows']);
        $report = fn () => (app()->forgetScopedInstances() ?: app(Reporting::class)->run('executive', $this->owner->currentTeam, $this->owner, Reporting::normalise('executive', []))['rows']);
        $this->assertSame($report(), $report());
    }

    public function test_every_kind_of_change_shows_at_once(): void
    {
        $before = $this->facts($this->kiln);

        // An edit: the last task moves, and the forecast with it.
        $b = $this->kiln->tasks()->where('title', 'Kiln B')->first();
        $b->forceFill(['due_date' => '2026-12-24'])->save();
        $this->assertSame('2026-12-24', $this->facts($this->kiln)['schedule']['forecast']);

        // An addition and a deletion change the counts.
        $extra = $this->kiln->tasks()->create(['title' => 'Kiln C', 'status' => 'done', 'priority' => 'low']);
        $this->assertSame($before['work']['total'] + 1, $this->facts($this->kiln)['work']['total']);
        app(ProjectWork::class)->deleteTask($extra);
        $this->assertSame($before['work']['total'], $this->facts($this->kiln)['work']['total']);

        // A task in another project that this one waits for: moving it late is a conflict here.
        $clinicA = $this->clinic->tasks()->where('title', 'Clinic A')->first();
        app(ProjectWork::class)->addDependency($b->fresh(), $clinicA, $this->owner);
        $conflicts = $this->facts($this->kiln)['schedule']['conflicts'];
        $clinicA->forceFill(['due_date' => '2026-12-30'])->save();
        $this->assertGreaterThan($conflicts, $this->facts($this->kiln)['schedule']['conflicts'], 'a change in the other project reaches this one');

        // Tomorrow, lateness is worked out again.
        Carbon::setTestNow('2026-11-11 09:00:00');
        $this->assertGreaterThan(0, $this->facts($this->kiln)['work']['overdue']);
    }

    public function test_money_changes_show_at_once_and_stay_hidden_from_those_who_may_not_see_it(): void
    {
        $line = $this->kiln->budgetLines()->first();
        $this->assertSame(0, $this->money($this->kiln)['total']['actual']);
        app(CostLedger::class)->addCost($this->kiln, $this->owner, ['budget_line_id' => $line->id, 'kind' => 'actual', 'description' => 'Bricks', 'amount_cents' => 250_000, 'dated' => '2026-11-03']);
        $this->assertSame(250_000, $this->money($this->kiln)['total']['actual']);

        // Labour: time charged to a line moves with the person's rate, even when only the rate changes.
        app(CostLedger::class)->chargeTimeTo($this->kiln->fresh(), $line->id);
        DB::table('time_entries')->insert(['team_id' => $this->kiln->team_id, 'project_id' => $this->kiln->id, 'user_id' => $this->owner->id, 'work_date' => '2026-11-03', 'minutes' => 120, 'billable' => true, 'created_at' => now(), 'updated_at' => now()]);
        // Labour counts approved time only: approving the week is a change the kept money must see.
        Timesheet::query()->forceCreate(['team_id' => $this->kiln->team_id, 'user_id' => $this->owner->id, 'week_start' => '2026-11-02', 'status' => 'approved']);
        app(LabourCost::class)->setRate($this->owner->currentTeam, $this->owner, $this->owner->id, 50_000, '2026-11-01');
        $first = $this->money($this->kiln)['total']['actual'];
        app(LabourCost::class)->setRate($this->owner->currentTeam, $this->owner, $this->owner->id, 80_000, '2026-11-01');
        $this->assertGreaterThan($first, $this->money($this->kiln)['total']['actual']);

        // Kept money is never shown to someone who may not see it.
        $member = User::factory()->create(['current_team_id' => $this->owner->currentTeam->id]);
        $this->owner->currentTeam->users()->attach($member, ['role' => 'editor']);
        app()->forgetScopedInstances();
        $rows = app(PortfolioOverview::class)->build(Project::query()->get(), $member)['rows'];
        $this->assertNull(collect($rows)->first(fn ($r) => $r['project']->is($this->kiln))['money']);
    }

    public function test_a_warm_page_costs_the_same_queries_for_more_projects(): void
    {
        $warm = function () {
            app()->forgetScopedInstances();
            app(PortfolioOverview::class)->build(Project::query()->get(), $this->owner);
            app()->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(PortfolioOverview::class)->build(Project::query()->get(), $this->owner);

            return count(DB::getQueryLog());
        };
        $few = $warm();
        foreach (range(1, 6) as $i) {
            $this->project('P'.$i);
        }
        $this->assertLessThanOrEqual($few + 6 * 2, $warm(), 'no more than a couple of light queries per extra project once kept');
        $this->assertInstanceOf(ProjectTask::class, $this->kiln->tasks()->first());
    }
}
