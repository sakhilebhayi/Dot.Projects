<?php

namespace Tests\Feature;

use App\Livewire\Workload\Board;
use App\Models\Project;
use App\Models\User;
use App\Services\TimeTracking;
use App\Services\Workload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P6d: planned load against capacity. Remaining estimates spread over working days to the
 * due date; overdue work in this week; unestimated and undated work counted, not guessed; capacity
 * set by the business owner or an admin; everyone else sees only their own load; private project
 * names hidden from those who cannot see them.
 */
class WorkloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00'); // a Wednesday
    }

    /** @return array{boss: User, admin: User, sipho: User, lerato: User, project: Project} */
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
        $sipho = $make('Sipho Fitter', 'editor');
        $lerato = $make('Lerato Planner', 'editor');
        $project = Project::create(['team_id' => $team->id, 'owner_id' => $boss->id, 'name' => 'Plant shutdown', 'status' => 'active']);

        return ['boss' => $boss, 'admin' => $admin, 'sipho' => $sipho, 'lerato' => $lerato, 'project' => $project->fresh()];
    }

    public function test_load_is_spread_over_working_days_and_unknowns_are_counted(): void
    {
        ['boss' => $boss, 'sipho' => $sipho, 'project' => $project] = $this->business();
        $task = fn (array $a) => $project->tasks()->create($a + ['status' => 'todo', 'priority' => 'medium', 'assignee_id' => $sipho->id]);
        $task(['title' => 'Gearbox', 'estimate_minutes' => 1800, 'start_date' => '2026-11-04', 'due_date' => '2026-11-10']);
        $task(['title' => 'Scaffold', 'estimate_minutes' => 2400, 'due_date' => '2026-11-06']);
        $late = $task(['title' => 'Permit', 'estimate_minutes' => 300, 'due_date' => '2026-10-30']);
        $task(['title' => 'Paint', 'estimate_minutes' => null, 'due_date' => '2026-11-20']);
        $task(['title' => 'Survey', 'estimate_minutes' => 360, 'due_date' => null]);
        $task(['title' => 'Done already', 'estimate_minutes' => 600, 'due_date' => '2026-11-05', 'status' => 'done']);
        $this->actingAs($sipho);
        app(TimeTracking::class)->log($sipho, $project, ['task_id' => $late->id, 'work_date' => '2026-10-28', 'minutes' => 120]);

        $row = app(Workload::class)->forPeople($project->team, collect([$sipho]), $boss)[0];
        $this->assertSame(['2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23', '2026-11-30', '2026-12-07'], array_keys($row['weeks']));
        $this->assertSame(1080 + 2400 + 180, $row['weeks']['2026-11-02']['minutes'], 'gearbox 3 of 5 days, scaffold all, 3h left of the overdue permit');
        $this->assertSame(720, $row['weeks']['2026-11-09']['minutes']);
        $this->assertSame([180, 360, 1, 120], [$row['overdue'], $row['undated'], $row['noEstimate'], $row['lastWeek']]);
        $this->assertSame(['Scaffold', 'Gearbox', 'Permit'], array_map(fn ($i) => $i['task']->title, $row['weeks']['2026-11-02']['items']));
        $this->assertSame(Workload::DEFAULT_MINUTES, $row['capacity']);

        $this->actingAs($boss)->get(route('workload.index'))->assertOk()
            ->assertSeeText('1 person over capacity in the next six weeks; Sipho Fitter by up to 21h in a week.')
            ->assertSeeText('1 open task has no estimate and 6h of estimated work has no due date');
        Livewire::actingAs($boss)->test(Board::class)->call('pick', $sipho->id, '2026-11-02')->assertSee('Sipho Fitter, week of 2 Nov')->assertSee('61h planned against 40h');
    }

    public function test_capacity_is_set_by_the_owner_or_an_admin(): void
    {
        ['admin' => $admin, 'sipho' => $sipho, 'lerato' => $lerato, 'project' => $project] = $this->business();

        Livewire::actingAs($admin)->test(Board::class)->call('editCapacity', $sipho->id)->assertSet('hours', '40')
            ->set('hours', '32.5')->set('note', 'Part time')->call('saveCapacity')->assertHasNoErrors();
        $this->assertSame(['minutes' => 1950, 'note' => 'Part time'], app(Workload::class)->capacities($project->team)[$sipho->id]);
        Livewire::actingAs($admin)->test(Board::class)->call('editCapacity', $sipho->id)->set('hours', '90')->call('saveCapacity')->assertHasErrors('capacity');
        Livewire::actingAs($admin)->test(Board::class)->call('editCapacity', $sipho->id)->set('hours', 'lots')->call('saveCapacity')->assertHasErrors('capacity');

        Livewire::actingAs($lerato)->test(Board::class)->call('editCapacity', $sipho->id)->assertForbidden();
        try {
            app(Workload::class)->setCapacity($project->team, $lerato, $sipho->id, 600, null);
            $this->fail('an editor set capacity');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('capacity', $e->errors());
        }
    }

    public function test_people_see_their_own_load_and_private_work_stays_private(): void
    {
        ['boss' => $boss, 'admin' => $admin, 'sipho' => $sipho, 'lerato' => $lerato, 'project' => $project] = $this->business();
        $private = Project::create(['team_id' => $project->team_id, 'owner_id' => $lerato->id, 'name' => 'Pay review', 'status' => 'active', 'visibility' => 'private']);
        $private->tasks()->create(['title' => 'Benchmark salaries', 'status' => 'todo', 'priority' => 'medium', 'assignee_id' => $lerato->id, 'estimate_minutes' => 600, 'due_date' => '2026-11-06']);

        $this->actingAs($sipho)->get(route('workload.index'))->assertOk()->assertDontSee('Lerato Planner')->assertSee('Only the business owner and admins see everyone');

        $row = collect(app(Workload::class)->forPeople($project->team, collect([$lerato]), $admin))->first();
        $this->assertSame(600, $row['weeks']['2026-11-02']['minutes'], 'private work still counts toward load');
        $this->assertNull($row['weeks']['2026-11-02']['items'][0]['project'], 'but its name is hidden from those who cannot see it');
        Livewire::actingAs($admin)->test(Board::class)->call('pick', $lerato->id, '2026-11-02')->assertSee('Work on a private project')->assertDontSee('Benchmark salaries');
        Livewire::actingAs($boss)->test(Board::class)->call('pick', $lerato->id, '2026-11-02')->assertSee('Benchmark salaries')->assertSee('Pay review');
    }
}
