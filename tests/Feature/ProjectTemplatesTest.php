<?php

namespace Tests\Feature;

use App\Livewire\Projects\CreateProject;
use App\Livewire\Projects\ProjectSettings;
use App\Livewire\Templates\Library;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\User;
use App\Services\ProjectTemplates;
use App\Support\Methodologies;
use App\Support\StarterTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** R-34 P13a: templates are data a project starts from, and a project can become one. */
class ProjectTemplatesTest extends TestCase
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

    private function templates(): ProjectTemplates
    {
        return app(ProjectTemplates::class);
    }

    public function test_every_starter_is_well_formed_and_survives_cleaning_unchanged(): void
    {
        foreach (StarterTemplates::all() as $key => $s) {
            $this->assertArrayHasKey($s['industry'], StarterTemplates::INDUSTRIES, $key);
            $this->assertArrayHasKey($s['methodology'], Methodologies::ALL, $key);
            $clean = $this->templates()->clean($s['structure']);
            $this->assertSame(count($s['structure']['tasks']), count($clean['tasks']), $key);
            $this->assertSame(array_sum(array_map(fn ($t) => count($t['after']), $s['structure']['tasks'])), array_sum(array_map(fn ($t) => count($t['after']), $clean['tasks'])), $key.' lost a dependency');
            $this->assertSame(count($s['structure']['risks'] ?? []), count($clean['risks']), $key.' risk category');
            $this->assertSame(count($s['structure']['budget'] ?? []), count($clean['budget']), $key.' budget category');
            foreach ($clean['tasks'] as $t) {
                $this->assertLessThanOrEqual($t['due'], $t['start'], $key.': '.$t['title'].' ends before it starts');
                $this->assertLessThanOrEqual($clean['phases'][$t['phase']]['due'], $t['due'], $key.': '.$t['title'].' ends after its phase');
            }
        }
    }

    public function test_a_starter_becomes_an_ordinary_project_dated_from_its_start(): void
    {
        $team = $this->owner->currentTeam;
        $p = $this->templates()->create($team, $this->owner, 'starter:shutdown', ['name' => 'Kiln 3 shutdown', 'start' => Carbon::parse('2027-01-11')]);
        $s = StarterTemplates::all()['shutdown']['structure'];

        $this->assertSame('cpm', $p->methodology);
        $this->assertSame('planning', $p->status);
        $this->assertSame('2027-01-11', $p->start_date->toDateString());
        $this->assertSame(Carbon::parse('2027-01-11')->addDays($s['days'])->toDateString(), $p->due_date->toDateString());
        $this->assertSame(count($s['phases']), $p->milestones()->count());
        $this->assertSame(count($s['tasks']), $p->tasks()->count());
        $this->assertSame(0, $p->tasks()->where('status', '!=', 'backlog')->count());
        $this->assertSame(2, $p->gates()->count());
        $this->assertSame(4, $p->gates()->first()->checks()->count());
        $this->assertSame(4, $p->risks()->count());
        $this->assertSame(4, $p->budgetLines()->count());
        $this->assertSame(0, (int) $p->budgetLines()->sum('budget_cents'), 'a template never brings amounts');
        $this->assertSame(array_sum(array_map(fn ($t) => count($t['after']), $s['tasks'])), DB::table('task_dependencies')->count());
        $first = $p->tasks()->orderBy('sort_order')->first();
        $this->assertSame('2027-01-11', $first->start_date->toDateString());
        $this->assertSame(1, DB::table('activities')->where('action', 'project.created')->whereJsonContains('changes->template', 'Planned maintenance shutdown')->count());
    }

    public function test_a_project_saved_as_a_template_rebuilds_the_same_structure_without_people_or_progress(): void
    {
        $team = $this->owner->currentTeam;
        $source = $this->templates()->create($team, $this->owner, 'starter:works-package', ['name' => 'Clinic', 'start' => Carbon::parse('2027-02-01')]);
        $task = $source->tasks()->orderBy('sort_order')->first();
        $task->forceFill(['assignee_id' => $this->owner->id, 'status' => 'done', 'estimate_minutes' => 480])->save();
        $task->checklist()->create(['body' => 'Client signs the brief', 'position' => 1]);
        $sub = $source->tasks()->create(['title' => 'Site visit', 'status' => 'todo', 'priority' => 'high', 'parent_id' => $task->id]);
        $source->budgetLines()->first()->forceFill(['budget_cents' => 9_000_000])->save();

        $template = $this->templates()->capture($source, $this->owner, 'Our clinic build', 'How we build clinics');
        $copy = $this->templates()->create($team, $this->owner, 'team:'.$template->id, ['name' => 'Second clinic', 'start' => Carbon::parse('2027-06-07')]);

        $this->assertSame('stage_gate', $copy->methodology);
        $this->assertSame($source->milestones()->count(), $copy->milestones()->count());
        $this->assertSame($source->tasks()->count(), $copy->tasks()->count());
        $this->assertSame($source->gates()->count(), $copy->gates()->count());
        $this->assertSame($source->risks()->count(), $copy->risks()->count());
        $this->assertSame(0, (int) $copy->budgetLines()->sum('budget_cents'));
        $first = $copy->tasks()->orderBy('sort_order')->first();
        $this->assertNull($first->assignee_id);
        $this->assertSame('backlog', $first->status);
        $this->assertSame(480, $first->estimate_minutes);
        $this->assertSame(['Client signs the brief'], $first->checklist()->pluck('body')->all());
        $this->assertSame('2027-06-07', $first->start_date->toDateString(), 'dates move with the new start');
        $this->assertSame($first->id, $copy->tasks()->where('title', 'Site visit')->value('parent_id'));
        $this->assertSame(1, $template->fresh()->uses);
    }

    public function test_a_hostile_structure_is_cleaned_before_it_touches_the_database(): void
    {
        $s = $this->templates()->clean([
            'phases' => [['title' => 'Only phase', 'due' => 99999]],
            'tasks' => [
                ['title' => 'A', 'phase' => 7, 'after' => [1], 'parent' => 1, 'priority' => 'critical'],
                ['title' => 'B', 'after' => [0, 0, 5, 1], 'parent' => 0],
            ],
            'risks' => [['title' => 'R', 'category' => 'nonsense', 'probability' => 9, 'impact' => -2]],
            'budget' => [['category' => 'yachts', 'name' => 'Boat']],
        ]);

        $this->assertSame(3650, $s['phases'][0]['due']);
        $this->assertNull($s['tasks'][0]['phase']);
        $this->assertSame('medium', $s['tasks'][0]['priority']);
        $this->assertTrue($s['tasks'][0]['parent'] === null || $s['tasks'][1]['parent'] === null, 'a subtask loop is broken');
        $this->assertSame(1, count($s['tasks'][0]['after']) + count($s['tasks'][1]['after']), 'a dependency loop is broken, duplicates and strays dropped');
        $this->assertSame(['other', 5, 1], [$s['risks'][0]['category'], $s['risks'][0]['probability'], $s['risks'][0]['impact']]);
        $this->assertSame([], $s['budget']);
    }

    public function test_templates_belong_to_their_business_and_only_their_keepers_change_them(): void
    {
        $team = $this->owner->currentTeam;
        $source = Project::create(['team_id' => $team->id, 'owner_id' => $this->owner->id, 'name' => 'S', 'status' => 'active']);
        $template = $this->templates()->capture($source, $this->owner, 'Ours', null);

        $other = User::factory()->withPersonalTeam()->create();
        $this->assertNull($this->templates()->find($other->currentTeam, 'team:'.$template->id), 'another business cannot use it');

        $member = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($member, ['role' => 'editor']);
        $this->expectException(ValidationException::class);
        $this->templates()->remove($template, $member);
    }

    public function test_a_viewer_cannot_save_a_project_as_a_template(): void
    {
        $team = $this->owner->currentTeam;
        $source = Project::create(['team_id' => $team->id, 'owner_id' => $this->owner->id, 'name' => 'S', 'status' => 'active']);
        $viewer = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($viewer, ['role' => 'viewer']);

        $this->expectException(ValidationException::class);
        $this->templates()->capture($source, $viewer, 'Mine', null);
        $this->assertSame(0, ProjectTemplate::query()->count());
    }

    public function test_the_new_project_page_creates_from_a_starter_with_its_methodology(): void
    {
        $this->get(route('projects.create'))->assertOk()->assertSee('How to start')->assertSee('Planned maintenance shutdown')->assertSee('None yet.');

        Livewire::test(CreateProject::class)
            ->set('source', 'starter:scrum-release')
            ->assertSet('methodology', 'scrum')
            ->assertSet('startDate', '2026-12-07')
            ->assertSee('What Software release in sprints adds')
            ->assertSee('Sprint 1')
            ->set('name', 'Mobile app 2.0')
            ->call('save')
            ->assertHasNoErrors();

        $p = Project::query()->firstWhere('name', 'Mobile app 2.0');
        $this->assertSame('scrum', $p->methodology);
        $this->assertSame(5, $p->milestones()->count());
        $this->get(route('projects.show', $p))->assertOk()->assertSee('Scrum');
    }

    public function test_a_blank_project_can_say_how_it_is_run_and_a_bad_choice_is_refused(): void
    {
        Livewire::test(CreateProject::class)->set('name', 'Blank one')->set('methodology', 'kanban')->call('save')->assertHasNoErrors();
        $this->assertSame('kanban', Project::query()->firstWhere('name', 'Blank one')->methodology);

        Livewire::test(CreateProject::class)->set('name', 'Bad')->set('methodology', 'chaos')->call('save')->assertHasErrors('methodology');
        Livewire::test(CreateProject::class)->set('source', 'starter:nope')->assertSet('source', '');
    }

    public function test_a_template_link_preselects_it_and_another_business_s_template_is_ignored(): void
    {
        $source = Project::create(['team_id' => $this->owner->currentTeam->id, 'owner_id' => $this->owner->id, 'name' => 'S', 'status' => 'active']);
        $mine = $this->templates()->capture($source, $this->owner, 'Our way', null);
        Livewire::withQueryParams(['template' => 'team:'.$mine->id])->test(CreateProject::class)->assertSet('source', 'team:'.$mine->id);

        $other = User::factory()->withPersonalTeam()->create();
        $this->actingAs($other);
        Livewire::withQueryParams(['template' => 'team:'.$mine->id])->test(CreateProject::class)->assertSet('source', '');
    }

    public function test_settings_save_a_project_as_a_template_and_the_library_manages_it(): void
    {
        $p = Project::create(['team_id' => $this->owner->currentTeam->id, 'owner_id' => $this->owner->id, 'name' => 'Kiln reline', 'status' => 'active']);
        $this->get(route('projects.settings', $p))->assertOk()->assertSee('Save as a template');

        Livewire::test(ProjectSettings::class, ['project' => $p])->set('templateName', 'Kiln work')->call('saveTemplate')->assertRedirect(route('templates.index'));
        $t = ProjectTemplate::query()->firstWhere('name', 'Kiln work');
        $this->get(route('templates.index'))->assertOk()->assertSee('Kiln work')->assertSee('Starters');

        Livewire::test(Library::class)->call('edit', $t->id)->set('name', 'Kiln relines')->call('save')->assertHasNoErrors();
        $this->assertSame('Kiln relines', $t->fresh()->name);
        Livewire::test(Library::class)->call('remove', $t->id);
        $this->assertNull($t->fresh());
        $this->assertTrue($p->fresh() !== null, 'removing a template never touches projects');
    }

    public function test_settings_change_the_methodology(): void
    {
        $p = Project::create(['team_id' => $this->owner->currentTeam->id, 'owner_id' => $this->owner->id, 'name' => 'P', 'status' => 'active']);
        Livewire::test(ProjectSettings::class, ['project' => $p])->set('methodology', 'stage_gate')->call('save')->assertHasNoErrors();
        $this->assertSame('stage_gate', $p->fresh()->methodology);
    }
}
