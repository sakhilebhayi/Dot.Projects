<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\TimeReport;
use App\Services\TimeTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * R-34 P6c: a project's time report for a period (totals, billable share, by task, by week, by
 * person for those who run the project), estimate against actual, and the CSV export.
 */
class TimeReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00'); // a Wednesday
    }

    /** @return array{boss: User, pm: User, worker: User, other: User, project: Project} */
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
        $other = $make('Olga Other');
        $project = Project::create(['team_id' => $team->id, 'owner_id' => $pm->id, 'name' => 'Plant shutdown', 'status' => 'active']);

        return ['boss' => $boss, 'pm' => $pm, 'worker' => $worker, 'other' => $other, 'project' => $project->fresh()];
    }

    public function test_periods_cover_the_right_days(): void
    {
        ['project' => $project] = $this->business();
        $p = fn (string $preset, ?string $f = null, ?string $t = null) => array_map(fn (Carbon $d) => $d->toDateString(), TimeReport::period($preset, $f, $t, $project));

        $this->assertSame(['2026-11-02', '2026-11-08'], $p('this_week'));
        $this->assertSame(['2026-10-26', '2026-11-01'], $p('last_week'));
        $this->assertSame(['2026-11-01', '2026-11-30'], $p('this_month'));
        $this->assertSame(['2026-10-01', '2026-10-31'], $p('last_month'));
        $this->assertSame(['2026-10-10', '2026-10-20'], $p('custom', '2026-10-20', '2026-10-10'), 'dates in either order');
        $this->assertSame(['2026-11-01', '2026-11-30'], $p('nonsense'));
    }

    public function test_the_report_adds_up_and_respects_approval(): void
    {
        ['pm' => $pm, 'worker' => $worker, 'other' => $other, 'project' => $project] = $this->business();
        $gearbox = $project->tasks()->create(['title' => 'Replace the gearbox', 'status' => 'in_progress', 'priority' => 'high', 'estimate_minutes' => 240]);
        $scaffold = $project->tasks()->create(['title' => 'Put up the scaffold', 'status' => 'todo', 'priority' => 'medium', 'estimate_minutes' => 600]);
        $time = app(TimeTracking::class);
        $this->actingAs($worker);
        $time->log($worker, $project, ['task_id' => $gearbox->id, 'work_date' => '2026-10-27', 'minutes' => 180, 'billable' => true]);
        $time->log($worker, $project, ['task_id' => $gearbox->id, 'work_date' => '2026-11-03', 'minutes' => 120, 'billable' => true]);
        $time->start($worker, $project, $scaffold->id, null, false);
        $this->actingAs($other);
        $time->log($other, $project, ['task_id' => null, 'work_date' => '2026-11-02', 'minutes' => 60]);

        $report = app(TimeReport::class);
        $s = $report->summary($report->entries($project, Carbon::parse('2026-10-01'), Carbon::parse('2026-11-30'), false));
        $this->assertSame([360, 300, 3, 2], [$s['total'], $s['billable'], $s['count'], $s['people']], 'the running timer does not count yet');
        $this->assertSame([['task' => 'Replace the gearbox', 'minutes' => 300, 'billable' => 300], ['task' => null, 'minutes' => 60, 'billable' => 0]], $s['byTask']);
        $this->assertSame(['2026-10-26' => 180, '2026-11-02' => 180], collect($s['byWeek'])->mapWithKeys(fn ($w) => [$w['week']->toDateString() => $w['minutes']])->all());

        $sheet = $time->sheet($worker, $project->team, Carbon::parse('2026-10-27'));
        $time->submit($sheet, $worker, $pm->id);
        $this->actingAs($pm);
        $time->approve($sheet->fresh(), $pm);
        $approved = $report->summary($report->entries($project, Carbon::parse('2026-10-01'), Carbon::parse('2026-11-30'), true));
        $this->assertSame([180, 1], [$approved['total'], $approved['count']], 'only the approved week');

        $est = $report->estimates($project);
        $this->assertSame(['Replace the gearbox', 'Put up the scaffold'], array_map(fn ($r) => $r['task']->title, $est));
        $this->assertSame([60, -600], [$est[0]['over'], $est[1]['over']]);
    }

    public function test_totals_for_everyone_detail_and_export_for_those_who_run_it(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'worker' => $worker, 'project' => $project] = $this->business();
        $task = $project->tasks()->create(['title' => 'Replace the gearbox', 'status' => 'in_progress', 'priority' => 'high', 'estimate_minutes' => 120]);
        $this->actingAs($worker);
        app(TimeTracking::class)->log($worker, $project, ['task_id' => $task->id, 'work_date' => '2026-11-03', 'minutes' => 150, 'note' => '=HYPERLINK("http://x")', 'billable' => true]);

        $this->actingAs($worker)->get(route('projects.time', $project))->assertOk()
            ->assertSeeText('2h 30m logged from 1 Nov to 30 Nov 2026, 2h 30m billable (100%), by 1 person.')
            ->assertSee('1 task is over its estimate')->assertSee('30m over')
            ->assertSee('Time by person is for the people who run this project')->assertDontSee('Download as CSV');
        $this->actingAs($worker)->get(route('projects.time.csv', $project))->assertForbidden();

        $this->actingAs($pm)->get(route('projects.time', $project))->assertSee('Wandile Worker')->assertSee('Download as CSV');
        $csv = $this->actingAs($pm)->get(route('projects.time.csv', ['project' => $project, 'period' => 'this_week']));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Date,Person,Task,Note,Hours,Minutes,Billable,Timesheet', $body);
        $this->assertStringContainsString('2026-11-03,"Wandile Worker","Replace the gearbox","\'=HYPERLINK(""http://x"")",2.50,150,yes,Open', $body);
        $this->actingAs($boss)->get(route('projects.time.csv', $project))->assertOk();

        $private = Project::create(['team_id' => $project->team_id, 'owner_id' => $pm->id, 'name' => 'Pay review', 'status' => 'active', 'visibility' => 'private']);
        $this->actingAs($worker)->get(route('projects.time', $private))->assertNotFound();
        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger)->get(route('projects.time', $project))->assertNotFound();
    }
}
