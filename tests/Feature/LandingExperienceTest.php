<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-50: the page a person arrives at after signing in through InfoDot. The original logo, a welcome
 * from their own name and business, exactly one primary action that works for their role, a real
 * project's date line, an honest empty state, the way back to InfoDot, and no figure that is not
 * counted from records.
 */
class LandingExperienceTest extends TestCase
{
    use RefreshDatabase;

    /** The platforms of the Dot ecosystem; a key outside this set has been discontinued. */
    private const PLATFORMS = [
        'agents', 'analytics', 'auction', 'central', 'charts', 'design', 'docs', 'dopemine', 'ehail', 'emall', 'engage', 'files',
        'finance', 'forms', 'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse', 'sheet', 'tasks', 'tutor', 'infodot',
    ];

    private User $owner;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 09:00:00');
        $this->owner = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Mokoena']);
        $this->team = $this->owner->currentTeam;
        $this->team->forceFill(['name' => 'Kathu Mining Services'])->save();
    }

    private function member(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => $role]);

        return $user;
    }

    /** Two open projects (one with overdue work and four dated milestones) and one completed. */
    private function seedWork(User $assignee): Project
    {
        $conveyor = Project::create(['team_id' => $this->team->id, 'owner_id' => $this->owner->id, 'name' => 'Conveyor 3 replacement', 'status' => 'active', 'start_date' => '2026-09-25', 'due_date' => '2027-01-28']);
        $conveyor->milestones()->create(['title' => 'Design signed off', 'due_date' => '2026-10-15', 'status' => 'completed', 'sort_order' => 1]);
        $steel = $conveyor->milestones()->create(['title' => 'Steel delivered', 'due_date' => '2026-11-10', 'status' => 'in_progress', 'sort_order' => 2]);
        $conveyor->milestones()->create(['title' => 'Belt installed', 'due_date' => '2026-12-18', 'status' => 'pending', 'sort_order' => 3]);
        $conveyor->tasks()->create(['title' => 'Approve drawings', 'status' => 'done', 'priority' => 'high']);
        $conveyor->tasks()->create(['title' => 'Clear steel through customs', 'status' => 'in_progress', 'priority' => 'high', 'milestone_id' => $steel->id, 'assignee_id' => $assignee->id, 'due_date' => '2026-11-08']);
        $conveyor->tasks()->create(['title' => 'Book the crane', 'status' => 'todo', 'priority' => 'medium', 'assignee_id' => $assignee->id, 'due_date' => '2026-11-23']);
        Project::create(['team_id' => $this->team->id, 'owner_id' => $this->owner->id, 'name' => 'Workshop roof repair', 'status' => 'planning']);
        Project::create(['team_id' => $this->team->id, 'owner_id' => $this->owner->id, 'name' => 'Safety file', 'status' => 'completed']);

        return $conveyor;
    }

    /** The arrival block only: from its opening tag to the start of the projects list. */
    private function arrival(TestResponse $response): string
    {
        $html = (string) $response->getContent();
        $from = strpos($html, '<section class="pgd-arrive"');
        $to = strpos($html, '<section class="pgd-desk') ?: strpos($html, '</main>');
        $this->assertNotFalse($from);
        $this->assertNotFalse($to);

        return substr($html, $from, $to - $from);
    }

    /** @return array{0: string, 1: string} the primary action's address and its label */
    private function primaryAction(TestResponse $response): array
    {
        $html = (string) $response->getContent();
        $this->assertSame(1, substr_count($html, 'data-primary-action'), 'exactly one element carries the primary-action marker');
        $this->assertSame(1, preg_match('/<a href="([^"]+)"[^>]*data-primary-action[^>]*>(.*?)<\/a>/s', $html, $m));

        return [html_entity_decode($m[1]), trim(strip_tags(preg_replace('/<span[^>]*>.*?<\/span>/s', '', $m[2])))];
    }

    /** @return array<string, array{0: string}> */
    public static function creatingRoles(): array
    {
        return ['owner' => ['owner'], 'administrator' => ['admin'], 'editor' => ['editor']];
    }

    #[DataProvider('creatingRoles')]
    public function test_people_who_may_create_get_one_primary_action_that_starts_a_project(string $role): void
    {
        $user = $role === 'owner' ? $this->owner : $this->member($role, 'Lerato Nkosi');
        $this->seedWork($user);

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        [$href, $label] = $this->primaryAction($response);

        $this->assertSame(route('projects.create'), $href);
        $this->assertSame('Start a project', $label);
        $this->actingAs($user)->get($href)->assertOk()->assertSee('How to start');
    }

    public function test_a_read_only_viewer_gets_one_primary_action_they_can_use(): void
    {
        $viewer = $this->member('viewer', 'Vusi Dlamini');
        $this->seedWork($this->owner);

        $response = $this->actingAs($viewer)->get(route('dashboard'))->assertOk()
            ->assertSee('Welcome, Vusi.')->assertSee('Conveyor 3 replacement')
            ->assertDontSee('Start a project')->assertDontSee('Start from a template');
        [$href, $label] = $this->primaryAction($response);

        $this->assertSame(route('portfolio.index'), $href);
        $this->assertSame('Review the portfolio', $label);
        $this->actingAs($viewer)->get($href)->assertOk();
        $this->actingAs($viewer)->get(route('projects.create'))->assertForbidden();
    }

    public function test_the_welcome_uses_the_persons_first_name_and_their_business(): void
    {
        $this->seedWork($this->owner);

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSee('Welcome, Thandi.')->assertDontSee('Welcome, Thandi Mokoena')
            ->assertSee('Kathu Mining Services, as of today, 14 Nov.')
            ->assertDontSee('Know when every project lands.', 'the product line is for the first visit only (R-51)');

        $empty = User::factory()->withPersonalTeam()->create(['name' => 'Naledi Zulu']);
        $empty->currentTeam->forceFill(['name' => 'Fresh Start Mining'])->save();
        $this->actingAs($empty)->get(route('dashboard'))->assertOk()
            ->assertSee('Know when every project lands.')
            ->assertSee('This is where Fresh Start Mining plans and runs its projects');
    }

    public function test_the_landing_shows_the_original_logo_files_unaltered(): void
    {
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSee('src="'.asset('images/logo.png').'" alt="Dot.Projects"', false)
            ->assertSee('src="'.asset('images/logo-light.png').'" alt="Dot.Projects"', false);

        $this->assertSame('9fd27b9d8b6215499e175ec8382f361b9aa90dfd60f552b66ca8a1b84ee8d885', hash_file('sha256', public_path('images/logo.png')));
        $this->assertSame('981812a241b4b97a36076dc906aa2c4e0c162b397328c219be5d0695611709e4', hash_file('sha256', public_path('images/logo-light.png')));
    }

    public function test_the_way_back_to_infodot_is_the_switcher_not_a_two_link_nav(): void
    {
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
            ->assertDontSee('<a href="https://infodot.co.za/home">InfoDot</a>', false)
            ->assertDontSee('aria-label="Dot ecosystem"', false)
            ->assertSee('<a href="https://infodot.co.za/platforms" class="dsw-all">See all platforms</a>', false);

        config(['ecosystem.platforms.infodot.url' => 'https://infodot.example.test/']);
        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertSee('<a href="https://infodot.example.test/platforms" class="dsw-all">See all platforms</a>', false);
    }

    public function test_a_new_member_with_nothing_sees_an_honest_empty_state(): void
    {
        $response = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSee('No projects yet.')
            ->assertSee('Start one; you can begin with just a name')
            ->assertDontSee('Given to you')->assertDontSee('id="ledger"', false);
        $this->assertSame(route('projects.create'), $this->primaryAction($response)[0]);

        // With no records there is nothing to count: today's date is the only figure in the arrival block.
        $text = html_entity_decode(strip_tags($this->arrival($response)));
        $this->assertStringContainsString('Today, 14 Nov', $text);
        $this->assertDoesNotMatchRegularExpression('/\d/', str_replace('Today, 14 Nov', '', $text));
    }

    public function test_a_viewer_with_nothing_is_told_so_plainly(): void
    {
        $viewer = $this->member('viewer', 'Vusi Dlamini');

        $this->actingAs($viewer)->get(route('dashboard'))->assertOk()
            ->assertSee('No projects yet. Projects appear here once someone in the business starts one you can see.');
    }

    public function test_every_figure_in_the_arrival_comes_from_the_seeded_records(): void
    {
        $conveyor = $this->seedWork($this->owner);

        $response = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSee('1 of 2 open projects')
            ->assertSee(route('projects.show', $conveyor))
            ->assertSee('1 of 3')                // tasks done, in the row and the panel
            ->assertSee('In 75 days')            // due 28 Jan 2027
            ->assertSee('2 open tasks')
            ->assertSee('Was due 8 Nov')->assertSee('Due 23 Nov')
            ->assertSeeInOrder(['Clear steel through customs', 'Book the crane']);

        // Strip the words the records themselves supply, then every number left must be one we seeded or counted.
        $text = html_entity_decode(strip_tags(preg_replace('/\s(style|title|aria-label|id|href|width|height)="[^"]*"/', '', $this->arrival($response))));
        preg_match_all('/\d+/', $text, $found);
        $allowed = ['1', '2', '14']; // 1 of 2 open projects is late; today is 14 Nov
        $this->assertSame([], array_values(array_diff(array_unique($found[0]), $allowed)), 'a number in the arrival does not come from the records');
    }

    public function test_work_in_another_business_never_reaches_the_landing(): void
    {
        $other = User::factory()->withPersonalTeam()->create();
        $theirs = Project::create(['team_id' => $other->currentTeam->id, 'owner_id' => $other->id, 'name' => 'Rival shaft sinking', 'status' => 'active']);
        $theirs->milestones()->create(['title' => 'Rival headgear', 'due_date' => '2026-12-01', 'status' => 'pending']);
        $theirs->tasks()->create(['title' => 'Rival chore', 'status' => 'todo', 'priority' => 'low', 'assignee_id' => $this->owner->id, 'due_date' => '2026-11-20']);

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSee('No projects yet.')
            ->assertDontSee('Rival shaft sinking')->assertDontSee('Rival headgear')->assertDontSee('Rival chore');
    }

    public function test_everything_the_page_offered_before_is_still_reachable(): void
    {
        $this->seedWork($this->owner);
        Project::create(['team_id' => $this->team->id, 'owner_id' => $this->owner->id, 'name' => 'Mothballed', 'status' => 'active'])->forceFill(['archived_at' => now()])->save();

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSee('Search projects by name')->assertSee('Any status')->assertSee('1 archived')
            ->assertSee('Next milestone')->assertSee('Tasks done')->assertSee('Workshop roof repair')->assertSee('Safety file')
            ->assertSee(route('templates.index'));
        $this->actingAs($this->owner)->get(route('dashboard', ['search' => 'roof']))->assertOk()
            ->assertSee('Workshop roof repair')
            ->assertSee('1 of 2 open projects'); // the arrival still reads the whole business under a filter
        $this->actingAs($this->owner)->get(route('templates.index'))->assertOk();
    }

    public function test_the_platform_list_holds_only_current_platforms(): void
    {
        $this->assertSame([], array_values(array_diff(array_keys(config('ecosystem.platforms')), self::PLATFORMS)));
        $this->assertSame('Dot.Projects', config('ecosystem.platforms.projects.name'));
    }
}
