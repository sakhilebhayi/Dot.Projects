<?php

namespace Tests\Feature;

use App\Livewire\Calendar\Business;
use App\Models\Project;
use App\Models\User;
use App\Services\BusinessCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** R-34 P13d: one month across the business's projects, only what the person can see. */
class BusinessCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->actingAs($this->owner);
    }

    private function project(string $name, array $extra = []): Project
    {
        $p = Project::create($extra + ['team_id' => $this->owner->currentTeam->id, 'owner_id' => $this->owner->id, 'name' => $name, 'status' => 'active']);
        $p->tasks()->create(['title' => $name.' task', 'status' => 'todo', 'priority' => 'medium', 'start_date' => '2026-11-09', 'due_date' => '2026-11-12', 'assignee_id' => $this->owner->id]);
        $p->milestones()->create(['title' => $name.' milestone', 'due_date' => '2026-11-20']);

        return $p;
    }

    public function test_the_month_gathers_every_visible_project_and_only_those(): void
    {
        $this->project('Kiln');
        $this->project('Clinic');
        $this->project('Retrenchments', ['visibility' => 'private']);
        $other = User::factory()->withPersonalTeam()->create();
        Project::create(['team_id' => $other->currentTeam->id, 'owner_id' => $other->id, 'name' => 'Elsewhere', 'status' => 'active'])
            ->tasks()->create(['title' => 'Elsewhere task', 'status' => 'todo', 'priority' => 'medium', 'due_date' => '2026-11-12']);

        $colleague = User::factory()->create(['current_team_id' => $this->owner->currentTeam->id]);
        $this->owner->currentTeam->users()->attach($colleague, ['role' => 'editor']);
        $this->actingAs($colleague);

        $e = app(BusinessCalendar::class)->events($colleague, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $this->assertEqualsCanonicalizing(['Kiln task', 'Clinic task'], array_column($e['2026-11-12'], 'title'));
        $this->assertSame('Kiln', collect($e['2026-11-12'])->firstWhere('title', 'Kiln task')['project']);
        $this->assertFalse(collect($e['2026-11-12'])->firstWhere('title', 'Kiln task')['mine']);

        $this->get(route('calendar.index'))->assertOk()->assertSee('Kiln task')->assertDontSee('Retrenchments')->assertDontSee('Elsewhere task');
    }

    public function test_its_queries_do_not_grow_with_the_number_of_projects(): void
    {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(BusinessCalendar::class)->events($this->owner, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

            return count(DB::getQueryLog());
        };
        $this->project('A');
        $this->project('B');
        $few = $count();
        foreach (range(1, 8) as $i) {
            $this->project('P'.$i);
        }
        $this->assertSame($few, $count());
    }

    public function test_filters_and_moving_respect_what_the_person_may_change(): void
    {
        $kiln = $this->project('Kiln');
        $this->project('Clinic');
        $task = $kiln->tasks()->first();

        Livewire::test(Business::class)->set('projectId', (string) $kiln->id)->assertSee('Kiln task')->assertDontSee('Clinic task')
            ->set('projectId', '')->set('show', 'milestone')->assertSee('Kiln milestone')->assertDontSee('Kiln task');
        Livewire::test(Business::class)->call('moveTask', $task->id, '2026-11-19')->assertHasNoErrors()->assertSet('day', '2026-11-19');
        $this->assertSame(['2026-11-16', '2026-11-19'], [$task->fresh()->start_date->toDateString(), $task->fresh()->due_date->toDateString()]);

        $viewer = User::factory()->create(['current_team_id' => $this->owner->currentTeam->id]);
        $this->owner->currentTeam->users()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer);
        $seen = collect(app(BusinessCalendar::class)->events($viewer, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'))['2026-11-19'] ?? [])->firstWhere('title', 'Kiln task');
        $this->assertFalse($seen['movable'], 'the bulk check agrees with Project::canBeChangedBy');
        $this->assertSame($kiln->fresh()->canBeChangedBy($viewer), $seen['movable']);
        Livewire::test(Business::class)->call('moveTask', $task->id, '2026-11-20')->assertForbidden();

        $outsider = User::factory()->withPersonalTeam()->create();
        $this->actingAs($outsider);
        Livewire::test(Business::class)->call('moveTask', $task->id, '2026-11-20')->assertNotFound();
    }
}
