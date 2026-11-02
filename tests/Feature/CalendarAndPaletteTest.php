<?php

namespace Tests\Feature;

use App\Livewire\CommandPalette;
use App\Livewire\Projects\CalendarView;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\CommandSearch;
use App\Services\Meetings;
use App\Services\ProjectCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** R-34 P13b: the project calendar and the command palette read the same records, and only what a person may see. */
class CalendarAndPaletteTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->actingAs($this->owner);
        $this->project = Project::create(['team_id' => $this->owner->currentTeam->id, 'owner_id' => $this->owner->id, 'name' => 'Kiln reline', 'status' => 'active', 'start_date' => '2026-11-02', 'due_date' => '2026-11-30']);
    }

    private function task(string $title, string $start, string $due, string $status = 'todo'): ProjectTask
    {
        return $this->project->tasks()->create(['title' => $title, 'status' => $status, 'priority' => 'medium', 'start_date' => $start, 'due_date' => $due]);
    }

    public function test_the_month_holds_due_dates_milestones_meetings_and_marks_lateness(): void
    {
        $this->task('Order bricks', '2026-11-01', '2026-11-03');
        $this->task('Fit the lining', '2026-11-05', '2026-11-12', 'done');
        $this->project->milestones()->create(['title' => 'Lining done', 'due_date' => '2026-11-20']);
        app(Meetings::class)->add($this->project, $this->owner, ['title' => 'Kick-off', 'kind' => 'team', 'held_at' => '2026-11-10 10:00']);

        $e = app(ProjectCalendar::class)->events($this->project, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), $this->owner);

        $this->assertTrue(collect($e['2026-11-03'])->firstWhere('title', 'Order bricks')['late']);
        $this->assertTrue(collect($e['2026-11-12'])->firstWhere('title', 'Fit the lining')['done']);
        $this->assertFalse(collect($e['2026-11-12'])->firstWhere('title', 'Fit the lining')['movable'], 'done work does not move');
        $this->assertSame('Milestone', $e['2026-11-20'][0]['type']);
        $this->assertSame('10:00', $e['2026-11-10'][0]['time']);
        $this->assertSame('Project due', $e['2026-11-30'][0]['title']);
        $this->assertSame('Project starts', $e['2026-11-02'][0]['title']);
    }

    public function test_moving_a_task_keeps_its_length_and_is_recorded(): void
    {
        $t = $this->task('Fit the lining', '2026-11-05', '2026-11-12');
        $this->get(route('projects.calendar', $this->project))->assertOk()->assertSee('November 2026')->assertSee('Fit the lining');

        Livewire::test(CalendarView::class, ['project' => $this->project])->call('moveTask', $t->id, '2026-11-19')->assertHasNoErrors()->assertSet('day', '2026-11-19');
        $t->refresh();
        $this->assertSame(['2026-11-12', '2026-11-19'], [$t->start_date->toDateString(), $t->due_date->toDateString()]);
        $this->assertDatabaseHas('activities', ['action' => 'task.rescheduled', 'subject_id' => $t->id]);

        $done = $this->task('Order bricks', '2026-11-01', '2026-11-03', 'done');
        Livewire::test(CalendarView::class, ['project' => $this->project])->call('moveTask', $done->id, '2026-11-20')->assertHasErrors('calendar');
    }

    public function test_a_viewer_cannot_move_work_and_the_month_navigates(): void
    {
        $t = $this->task('Fit the lining', '2026-11-05', '2026-11-12');
        $viewer = User::factory()->create(['current_team_id' => $this->owner->currentTeam->id]);
        $this->owner->currentTeam->users()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer);

        Livewire::test(CalendarView::class, ['project' => $this->project])->call('moveTask', $t->id, '2026-11-20')->assertForbidden();
        Livewire::test(CalendarView::class, ['project' => $this->project])->call('go', 1)->assertSet('month', '2026-12')->assertSee('December 2026')
            ->set('show', 'meeting')->assertDontSee('Fit the lining');
    }

    public function test_the_palette_finds_only_what_the_person_can_open(): void
    {
        $this->task('Reline the kiln shell', '2026-11-05', '2026-11-12');
        Project::create(['team_id' => $this->owner->currentTeam->id, 'owner_id' => User::factory()->create()->id, 'name' => 'Kiln retrenchments', 'status' => 'active', 'visibility' => 'private']);
        $other = User::factory()->withPersonalTeam()->create();
        $theirs = Project::create(['team_id' => $other->currentTeam->id, 'owner_id' => $other->id, 'name' => 'Kiln elsewhere', 'status' => 'active']);
        $theirs->tasks()->create(['title' => 'Kiln secret task', 'status' => 'todo', 'priority' => 'medium']);

        // The business owner sees its private projects, as everywhere; a colleague not on one does not.
        $this->assertCount(2, app(CommandSearch::class)->search($this->owner, 'kiln')['Projects']);
        $colleague = User::factory()->create(['current_team_id' => $this->owner->currentTeam->id]);
        $this->owner->currentTeam->users()->attach($colleague, ['role' => 'editor']);
        $this->actingAs($colleague);
        $found = app(CommandSearch::class)->search($colleague, 'KILN');
        $this->assertSame(['Kiln reline'], array_column($found['Projects'], 'label'), 'case-insensitive, and never a private or another business\'s project');
        $this->assertSame(['Reline the kiln shell'], array_column($found['Tasks'], 'label'));
        $this->assertArrayNotHasKey('Tasks', app(CommandSearch::class)->search($colleague, '%'), 'a wildcard is matched as text');
        $this->assertContains('Templates', array_column(app(CommandSearch::class)->search($colleague, 'starter')['Go to'], 'label'));
        $this->assertNotContains('Data and security', array_column(app(CommandSearch::class)->search($colleague, 'security')['Go to'] ?? [], 'label'), 'pages for owners and admins only');
    }

    public function test_the_palette_adds_a_task_to_the_project_on_screen_only_for_those_who_may(): void
    {
        // Closed, the palette finds nothing, so pages carry no results; opening it loads them.
        $this->get(route('projects.show', $this->project))->assertOk()->assertSee('data-cmd', false);
        Livewire::test(CommandPalette::class, ['projectId' => $this->project->id])->assertDontSee('Kiln reline')->set('open', true)->assertSee('Kiln reline');
        // Enter opens the first item: after a search that must be a match, never the write action.
        $this->task('Order the refractory bricks first', '2026-11-05', '2026-11-06');
        Livewire::test(CommandPalette::class, ['projectId' => $this->project->id])->set('open', true)->set('q', 'refractory')
            ->assertSeeInOrder(['Order the refractory bricks first', 'as a task']);
        Livewire::test(CommandPalette::class, ['projectId' => $this->project->id])->set('open', true)->set('q', 'Order the refractory bricks')
            ->assertSee('as a task')->call('addTask')->assertRedirect();
        $this->assertSame(1, $this->project->tasks()->where('title', 'Order the refractory bricks')->where('status', 'backlog')->count());

        $viewer = User::factory()->create(['current_team_id' => $this->owner->currentTeam->id]);
        $this->owner->currentTeam->users()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer);
        Livewire::test(CommandPalette::class, ['projectId' => $this->project->id])->set('open', true)->set('q', 'Sneaky')->assertDontSee('as a task')->call('addTask')->assertForbidden();

        $outsider = User::factory()->withPersonalTeam()->create();
        $this->actingAs($outsider);
        Livewire::test(CommandPalette::class, ['projectId' => $this->project->id])->set('open', true)->set('q', 'Sneaky')->call('addTask')->assertNotFound();
    }
}
