<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\Governance;
use App\Services\ProjectFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * R-51: the dashboard as one ruled ledger beside the selected project. Health is worked out from records (never
 * stored), every figure is counted from the loaded rows, and nothing from another business or a private project the
 * viewer may not see reaches the page.
 */
class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

    private User $boss;

    private Project $late;

    private Project $risky;

    private Project $fine;

    private Project $done;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 09:00:00');
        $this->boss = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Mokoena']);
        $team = $this->boss->currentTeam;
        $mk = fn (string $name, string $status, array $extra = []) => Project::create(['team_id' => $team->id, 'owner_id' => $this->boss->id, 'name' => $name, 'status' => $status] + $extra);

        $this->fine = $mk('Warehouse extension', 'active', ['due_date' => '2027-04-23']);
        $this->done = $mk('ERP data migration', 'completed', ['due_date' => '2026-10-15']);
        $this->risky = $mk('Plant B conveyor', 'active', ['due_date' => '2027-01-28']);
        $this->late = $mk('Kathu go-live', 'active', ['due_date' => '2026-11-26']);

        $this->late->milestones()->create(['title' => 'Site acceptance', 'due_date' => '2026-11-04', 'status' => 'pending', 'sort_order' => 1]);
        foreach (range(1, 4) as $n) {
            $this->late->tasks()->create(['title' => "Kathu task $n", 'status' => $n <= 2 ? 'done' : 'todo', 'priority' => 'low', 'due_date' => $n === 3 ? '2026-11-06' : null]);
        }
        $this->done->tasks()->create(['title' => 'Cut over', 'status' => 'done', 'priority' => 'low']);
        $this->actingAs($this->boss);
        app(Governance::class)->addRisk($this->risky, $this->boss, ['title' => 'Bricks late', 'probability' => 4, 'impact' => 5]);
    }

    private function member(string $role, string $name = 'Lerato Nkosi'): User
    {
        $u = User::factory()->create(['name' => $name, 'current_team_id' => $this->boss->currentTeam->id]);
        $this->boss->currentTeam->users()->attach($u, ['role' => $role]);

        return $u;
    }

    public function test_each_open_project_is_rated_from_its_records_and_the_worst_come_first(): void
    {
        $html = (string) $this->actingAs($this->boss)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Off track', $html);
        $this->assertStringContainsString('At risk', $html);
        $this->assertStringContainsString('On track', $html);
        $order = [strpos($html, 'Kathu go-live</a>'), strpos($html, 'Plant B conveyor</a>'), strpos($html, 'Warehouse extension</a>'), strpos($html, 'ERP data migration</a>')];
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'Off track, then At risk, then On track, then the completed one');
        $this->assertSame(1, substr_count($html, 'data-primary-action'));
    }

    public function test_a_completed_project_is_never_rated(): void
    {
        $html = (string) $this->actingAs($this->boss)->get(route('dashboard'))->assertOk()->getContent();
        $row = substr($html, strpos($html, 'data-id="'.$this->done->id.'"'));
        $row = substr($row, 0, strpos($row, '</tr>'));

        $this->assertStringContainsString('Done', $row);
        $this->assertStringNotContainsString('pg-pill', $row);
        $this->assertStringContainsString('data-health="3"', $row);
        $this->actingAs($this->boss)->get(route('dashboard', ['project' => $this->done->id]))->assertOk()
            ->assertSee('Not rated: the project is completed.');
    }

    public function test_the_figures_come_from_the_loaded_rows(): void
    {
        $html = (string) $this->actingAs($this->boss)->get(route('dashboard'))->assertOk()
            ->assertSee('1 of 3 open projects') // only Kathu go-live has overdue work; the completed one is not open
            ->getContent();
        $row = substr($html, strpos($html, 'data-id="'.$this->late->id.'"'));
        $row = substr($row, 0, strpos($row, '</tr>'));

        $this->assertStringContainsString('2 of 4', $row);          // done / total
        $this->assertStringContainsString('data-over="1"', $row);   // one open task past its date
        $this->assertStringContainsString('--p:0.5', $row);          // the bar is done / total tasks, nothing weighted
        $this->assertStringContainsString('Was due 4 Nov', $row);
        $this->assertStringContainsString('26 Nov 2026', $row);
        $this->assertMatchesRegularExpression('/<span><b>3<\/b> open<\/span>\s*<span><b>1<\/b> completed<\/span>/', $html);
    }

    public function test_the_selected_project_panel_shows_its_facts_and_the_address_picks_the_row(): void
    {
        $this->actingAs($this->boss)->get(route('dashboard'))->assertOk()
            ->assertDontSee('data-for="'.$this->late->id.'" hidden', false); // the first row, the most urgent, is the one showing

        $page = $this->actingAs($this->boss)->get(route('dashboard', ['project' => $this->fine->id]))->assertOk();
        $html = (string) $page->getContent();
        $this->assertMatchesRegularExpression('/data-for="'.$this->fine->id.'"\s*>/', $html);
        $this->assertMatchesRegularExpression('/data-for="'.$this->late->id.'"\s+hidden/', $html);
        $this->assertMatchesRegularExpression('/<tr data-id="'.$this->fine->id.'"[^>]*class="is-selected"/', $html);
    }

    public function test_a_project_in_the_address_that_is_not_loaded_is_ignored(): void
    {
        $other = User::factory()->withPersonalTeam()->create();
        $theirs = Project::create(['team_id' => $other->currentTeam->id, 'owner_id' => $other->id, 'name' => 'Rival shaft', 'status' => 'active']);

        foreach ([$theirs->id, 'abc', '-3', '9999999'] as $ask) {
            $html = (string) $this->actingAs($this->boss)->get(route('dashboard', ['project' => $ask]))->assertOk()->assertDontSee('Rival shaft')->getContent();
            $this->assertMatchesRegularExpression('/<tr data-id="'.$this->late->id.'"[^>]*class="is-selected"/', $html, 'falls back to the first row');
            $this->assertStringNotContainsString('data-for="'.$theirs->id.'"', $html);
        }
    }

    public function test_nothing_from_another_business_or_a_private_project_reaches_the_page(): void
    {
        $other = User::factory()->withPersonalTeam()->create();
        $theirs = Project::create(['team_id' => $other->currentTeam->id, 'owner_id' => $other->id, 'name' => 'Rival shaft', 'status' => 'active', 'due_date' => '2026-10-01']);
        $theirs->tasks()->create(['title' => 'Rival chore', 'status' => 'todo', 'priority' => 'low', 'assignee_id' => $this->boss->id, 'due_date' => '2026-10-02']);
        $secret = Project::create(['team_id' => $this->boss->currentTeam->id, 'owner_id' => $this->boss->id, 'name' => 'Retrenchments', 'status' => 'active', 'visibility' => 'private', 'due_date' => '2026-10-01']);
        $ed = $this->member('editor');

        $this->actingAs($this->boss)->get(route('dashboard'))->assertOk()->assertSee('Retrenchments')->assertDontSee('Rival shaft')->assertDontSee('Rival chore');
        $page = $this->actingAs($ed)->get(route('dashboard'))->assertOk()->assertDontSee('Retrenchments')->assertDontSee('Rival shaft');
        $this->assertStringNotContainsString('data-for="'.$secret->id.'"', (string) $page->getContent());
        // The headline counts what this person may see: the private project's overdue state is not in their number.
        $page->assertSee('1 of 3 open projects');
    }

    public function test_the_page_renders_for_every_role_with_the_right_lead_action(): void
    {
        foreach (['admin', 'editor'] as $role) {
            $u = $this->member($role, "P $role");
            $html = (string) $this->actingAs($u)->get(route('dashboard'))->assertOk()->assertSee('Start from a template')->getContent();
            $this->assertSame(1, substr_count($html, 'data-primary-action'));
            $this->assertStringContainsString(route('projects.create'), $html);
        }
        $viewer = $this->member('viewer', 'Vusi Dlamini');
        $html = (string) $this->actingAs($viewer)->get(route('dashboard'))->assertOk()->assertSee('Review the portfolio')->assertSee('Kathu go-live')
            ->assertSee('No open task is assigned to you.')->assertDontSee('Start from a template')->getContent();
        $this->assertSame(1, substr_count($html, 'data-primary-action'));
        $this->assertStringContainsString('class="pgd-desk "', $html, 'a viewer never gets the member-first order');
    }

    public function test_a_member_with_given_work_sees_it_first_and_an_owner_does_not(): void
    {
        $ed = $this->member('editor');
        $this->late->tasks()->create(['title' => 'Book the crane', 'status' => 'todo', 'priority' => 'low', 'assignee_id' => $ed->id, 'due_date' => '2026-11-20']);

        $this->actingAs($ed)->get(route('dashboard'))->assertOk()->assertSee('pgd-desk pgd-desk--member', false)->assertSee('Book the crane')->assertSee('1 open task');
        $this->actingAs($this->boss)->get(route('dashboard'))->assertOk()->assertDontSee('pgd-desk--member', false);
    }

    public function test_a_failure_working_out_health_does_not_blank_the_page(): void
    {
        // ProjectFacts is final, so it is wrapped as a proxy mock around a real instance.
        $broken = \Mockery::mock(new ProjectFacts);
        $broken->shouldReceive('prime')->andThrow(new \RuntimeException('cache down'));
        $broken->shouldReceive('facts')->andThrow(new \RuntimeException('cache down'));
        $this->app->instance(ProjectFacts::class, $broken);

        $this->actingAs($this->boss)->get(route('dashboard'))->assertOk()
            ->assertSee('Kathu go-live')->assertSee('2 of 4')
            ->assertSee('Health could not be worked out just now.')->assertSee('Not worked out');
    }

    public function test_a_filter_with_no_match_keeps_the_frame_and_says_so(): void
    {
        $this->actingAs($this->boss)->get(route('dashboard', ['search' => 'zzz']))->assertOk()
            ->assertSee('No project matches. Try another name or status.')->assertSee('Search projects by name')->assertSee('Clear')
            ->assertDontSee('id="ledger"', false);
    }

    public function test_the_headline_counts_only_when_there_is_something_to_count(): void
    {
        Project::query()->get()->each->forceFill(['status' => 'completed'])->each->save();
        $this->actingAs($this->boss)->get(route('dashboard'))->assertOk()->assertSee('No open projects. Everything here is completed or archived.');
    }

    public function test_the_page_ships_its_script_only_as_an_external_chunk_under_the_nonce_policy(): void
    {
        $html = (string) $this->actingAs($this->boss)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/\son(click|mouseover|focus)=/', $html);
        $this->assertStringContainsString('data-pgd', $html);
    }
}
