<?php

namespace Tests\Feature;

use App\Livewire\Projects\TaskPanel;
use App\Livewire\Time\MyWeek;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ProjectPeople;
use App\Services\TimeTracking;
use App\Support\Duration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P6b: time people log themselves (by hand or with a timer), on a project and maybe a task,
 * and the weekly timesheet that goes to an approver and comes back approved or returned.
 */
class TimeTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00'); // a Wednesday
    }

    /** @return array{boss: User, pm: User, worker: User, viewer: User, project: Project, task: ProjectTask} */
    private function business(): array
    {
        $boss = User::factory()->withPersonalTeam()->create(['name' => 'Bongi Boss']);
        $team = $boss->currentTeam;
        $make = function (string $name, string $role) use ($team) {
            $u = User::factory()->create(['name' => $name, 'current_team_id' => $team->id]);
            $team->users()->attach($u, ['role' => $role]);

            return $u;
        };
        $pm = $make('Palesa PM', 'editor');
        $worker = $make('Wandile Worker', 'editor');
        $viewer = $make('Vusi Viewer', 'viewer');
        $project = Project::create(['team_id' => $team->id, 'owner_id' => $pm->id, 'name' => 'Plant shutdown', 'status' => 'active']);
        $task = $project->tasks()->create(['title' => 'Replace the gearbox', 'status' => 'in_progress', 'priority' => 'high', 'estimate_minutes' => 240]);

        return ['boss' => $boss, 'pm' => $pm, 'worker' => $worker, 'viewer' => $viewer, 'project' => $project->fresh(), 'task' => $task->fresh()];
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

    public function test_durations_read_the_ways_people_write_them(): void
    {
        foreach (['1.5' => 90, '1:30' => 90, '90m' => 90, '1h 30m' => 90, '2h' => 120, '0,25' => 15, '45min' => 45] as $text => $minutes) {
            $this->assertSame($minutes, Duration::parse($text), $text);
        }
        $this->assertNull(Duration::parse('a while'));
        $this->assertSame(['45m', '2h', '1h 30m'], [Duration::format(45), Duration::format(120), Duration::format(90)]);
    }

    public function test_people_log_their_own_time_within_the_rules(): void
    {
        ['pm' => $pm, 'worker' => $worker, 'viewer' => $viewer, 'project' => $project, 'task' => $task] = $this->business();
        $time = app(TimeTracking::class);
        $this->actingAs($worker);

        $this->refuses(fn () => $time->log($viewer, $project, ['work_date' => '2026-11-03', 'minutes' => 60]), 'project');
        $this->refuses(fn () => $time->log($worker, $project, ['work_date' => '2026-11-05', 'minutes' => 60]), 'work_date');
        $other = Project::create(['team_id' => $project->team_id, 'owner_id' => $pm->id, 'name' => 'Other', 'status' => 'active']);
        $elsewhere = $other->tasks()->create(['title' => 'Elsewhere', 'status' => 'todo']);
        $this->refuses(fn () => $time->log($worker, $project, ['task_id' => $elsewhere->id, 'work_date' => '2026-11-03', 'minutes' => 60]), 'task_id');

        $e = $time->log($worker, $project, ['task_id' => $task->id, 'work_date' => '2026-11-03', 'minutes' => 23 * 60, 'note' => 'Shutdown shift', 'billable' => true]);
        $this->refuses(fn () => $time->log($worker, $project, ['work_date' => '2026-11-03', 'minutes' => 90]), 'minutes');
        $this->refuses(fn () => $time->update($e, $pm, ['minutes' => 60]), 'entry');
        $time->update($e, $worker, ['minutes' => 8 * 60]);
        $this->assertSame(480, $e->fresh()->minutes);

        Livewire::actingAs($worker)->test(MyWeek::class)->set('project', (string) $project->id)->set('hours', '1h 30m')->set('date', '2026-11-04')->call('save')->assertHasNoErrors();
        $this->assertSame(570, (int) TimeEntry::query()->where('user_id', $worker->id)->sum('minutes'));
        Livewire::actingAs($worker)->test(MyWeek::class)->set('project', (string) $project->id)->set('hours', 'a while')->call('save')->assertHasErrors('minutes');

        $time->log($worker, $project, ['work_date' => '2026-11-01', 'minutes' => 30]);
        $this->assertSame(30, (int) $time->sheet($worker, $project->team, Carbon::parse('2026-10-26'))->entries()->sum('minutes'), 'Sunday belongs to its week');
        $this->assertSame(1, Timesheet::query()->count());
        $time->sheet($worker, $project->team, Carbon::parse('2026-10-29'));
        $this->assertSame(1, Timesheet::query()->count(), 'one sheet per person per week');

        $private = Project::create(['team_id' => $project->team_id, 'owner_id' => $pm->id, 'name' => 'Pay review', 'status' => 'active', 'visibility' => 'private']);
        $this->refuses(fn () => $time->log($worker, $private, ['work_date' => '2026-11-03', 'minutes' => 30]), 'project');
        $this->assertNotContains($private->id, Livewire::actingAs($worker)->test(MyWeek::class)->instance()->projects->pluck('id')->all());
    }

    public function test_one_timer_runs_at_a_time_and_stops_rounded_up(): void
    {
        ['worker' => $worker, 'project' => $project, 'task' => $task] = $this->business();
        $time = app(TimeTracking::class);
        $this->actingAs($worker);

        $first = $time->start($worker, $project, $task->id, 'Stripping the old unit', true);
        Carbon::setTestNow('2026-11-04 10:31:30');
        $second = $time->start($worker, $project, null, null, false);
        $this->assertSame(92, $first->fresh()->minutes, '1h 31m 30s rounds up to 92 minutes');
        $this->assertNull($first->fresh()->running_since);
        $this->assertTrue($second->fresh()->running());

        Carbon::setTestNow('2026-11-04 10:46:00');
        $this->assertSame(15, $time->running($worker)->elapsed());
        Livewire::actingAs($worker)->test(TaskPanel::class, ['project' => $project, 'task' => $task])->assertSee('Switch the timer here');
        $time->stop($worker);
        $this->assertNull($time->running($worker));
        $this->assertSame(15, $second->fresh()->minutes);
    }

    public function test_a_week_goes_for_approval_and_comes_back_approved_or_returned(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'worker' => $worker, 'project' => $project, 'task' => $task] = $this->business();
        $time = app(TimeTracking::class);
        $this->actingAs($worker);
        $sheet = $time->sheet($worker, $project->team, Carbon::today());
        $this->refuses(fn () => $time->submit($sheet, $worker, $pm->id), 'timesheet');

        $time->log($worker, $project, ['task_id' => $task->id, 'work_date' => '2026-11-02', 'minutes' => 300]);
        $this->assertSame(['Bongi Boss', 'Palesa PM'], $time->approversFor($sheet)->pluck('name')->all(), 'the project owner and the business owner, never the worker');
        $time->start($worker, $project, null, null, false);
        $this->refuses(fn () => $time->submit($sheet, $worker, $pm->id), 'timesheet');
        $time->stop($worker);
        $this->refuses(fn () => $time->submit($sheet, $worker, $worker->id), 'approver');

        Livewire::actingAs($worker)->test(MyWeek::class)->set('approver', (string) $pm->id)->call('submit')->assertHasNoErrors();
        $sheet->refresh();
        $this->assertSame(['submitted', $pm->id], [$sheet->status, $sheet->approver_id]);
        $this->assertSame(1, $pm->notifications()->where('data->type', 'timesheet_submitted')->count());
        $this->refuses(fn () => $time->log($worker, $project, ['work_date' => '2026-11-03', 'minutes' => 30]), 'work_date');
        $this->refuses(fn () => $time->approve($sheet, $boss), 'timesheet');

        $review = Livewire::actingAs($pm)->test(MyWeek::class)->set('approve', (string) $sheet->id);
        $review->assertSee('Wandile Worker, week of 2 Nov')->call('returnSheet', $sheet->id)->assertHasErrors('return_note');
        $review->set('returnNote', 'Split Monday between the gearbox and the scaffold')->call('returnSheet', $sheet->id)->assertHasNoErrors();
        $this->assertSame('returned', $sheet->fresh()->status);
        $this->assertSame(1, $worker->notifications()->where('data->type', 'timesheet_returned')->count());

        $time->log($worker, $project, ['work_date' => '2026-11-02', 'minutes' => 60, 'note' => 'Scaffold']);
        $time->submit($sheet->fresh(), $worker, $pm->id);
        Livewire::actingAs($pm)->test(MyWeek::class)->call('approveSheet', $sheet->id)->assertHasNoErrors();
        $this->assertSame(['approved', $pm->id], [$sheet->fresh()->status, $sheet->fresh()->decided_by]);
        $entry = TimeEntry::query()->where('user_id', $worker->id)->first();
        $this->refuses(fn () => $time->delete($entry, $worker), 'work_date');

        $this->actingAs($worker)->get(route('time.index', ['week' => '2026-11-02']))->assertOk()->assertSee('Approved by Palesa PM');
    }

    public function test_the_split_by_person_is_for_those_who_run_the_project(): void
    {
        ['pm' => $pm, 'worker' => $worker, 'project' => $project, 'task' => $task] = $this->business();
        $other = User::factory()->create(['name' => 'Olga Other', 'current_team_id' => $project->team_id]);
        $project->team->users()->attach($other, ['role' => 'editor']);
        $time = app(TimeTracking::class);
        $this->actingAs($worker);
        $time->log($worker, $project, ['task_id' => $task->id, 'work_date' => '2026-11-03', 'minutes' => 180]);
        $this->actingAs($other);
        $time->log($other, $project, ['task_id' => $task->id, 'work_date' => '2026-11-03', 'minutes' => 120]);

        $mine = Livewire::actingAs($worker)->test(TaskPanel::class, ['project' => $project, 'task' => $task])->instance()->time;
        $this->assertSame([300, 180, null], [$mine['total'], $mine['mine'], $mine['people']]);
        Livewire::actingAs($worker)->test(TaskPanel::class, ['project' => $project, 'task' => $task])->assertSee('5h of 4h estimated')->assertSee('1h over the estimate');

        $theirs = Livewire::actingAs($pm)->test(TaskPanel::class, ['project' => $project, 'task' => $task])->instance()->time;
        $this->assertSame(['Wandile Worker', 'Olga Other'], $theirs['people']->pluck('name')->all());

        app(ProjectPeople::class)->add($project, $pm, $worker->id, 'manager');
        $this->assertNotNull(Livewire::actingAs($worker)->test(TaskPanel::class, ['project' => $project, 'task' => $task])->instance()->time['people']);
    }
}
