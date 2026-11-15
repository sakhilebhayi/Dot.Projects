<?php

namespace Tests\Feature;

use App\Livewire\HeaderSearch;
use App\Livewire\NotificationBell;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-52: Roboto everywhere (self-hosted), the shared dot-ui header with a real search on every page, a short navigation with the
 * rest in named groups, and one drawer for phones (the 12 to 14 icon bottom bar is gone). The search keeps the command palette's
 * engine, so what it finds is still decided by the team and private-project scopes.
 */
class RobotoAndHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $teamName = 'Kiln Co'): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $teamName, 'personal_team' => false]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    private function project(User $owner, string $name, string $visibility = 'team'): Project
    {
        return Project::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => $name, 'status' => 'active', 'visibility' => $visibility]);
    }

    private function resource(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    // ── Roboto ──────────────────────────────────────────────────────────────

    public function test_roboto_is_declared_and_its_files_are_served_from_this_site(): void
    {
        $css = $this->resource('public/dot-ui/css/dot-roboto.css');
        $this->assertStringContainsString("font-family: 'Roboto'", str_replace('"', "'", $css));
        preg_match_all('/url\(\s*[\'"]?([^\'")]+\.woff2)/', $css, $files);
        $this->assertNotEmpty($files[1]);
        foreach ($files[1] as $file) {
            $this->assertFileExists(public_path('dot-ui/css/'.$file), $file);
        }

        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('class="dot-roboto"', $html);
        $this->assertStringContainsString(asset('dot-ui/css/dot-ui.min.css'), $html);
        $this->assertStringContainsString(asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2'), $html, 'preloaded from this origin');
    }

    public function test_no_page_reaches_for_an_external_font_and_no_other_text_face_is_declared(): void
    {
        $owner = $this->owner();
        $pages = [
            'welcome' => $this->get('/')->getContent(),
            'sign in' => $this->get('/login')->getContent(),
            'not found' => $this->get('/nowhere-at-all')->getContent(),
        ];
        $pages['signed in'] = $this->actingAs($owner)->get(route('dashboard'))->getContent();
        foreach ($pages as $name => $html) {
            $this->assertStringNotContainsString('fonts.googleapis.com', $html, $name);
            $this->assertStringNotContainsString('fonts.gstatic.com', $html, $name);
            $this->assertStringNotContainsString('cdn.tailwindcss.com', $html, $name);
            $this->assertMatchesRegularExpression('#dot-ui/css/(dot-ui|dot-roboto)\.min\.css#', $html, $name.' loads Roboto from this site');
        }

        $sources = [
            'resources/css/programme.css', 'resources/css/app.css', 'resources/css/workspace.css', 'resources/views/layouts/app.blade.php', 'resources/views/layouts/guest.blade.php',
            'resources/views/welcome.blade.php', 'resources/views/errors/_ecosystem-layout.blade.php', 'tailwind.config.js', 'tailwind.workspace.config.js',
        ];
        foreach ($sources as $file) {
            $text = $this->resource($file);
            foreach (['Schibsted', 'Archivo', 'Source Sans', 'DM Mono', 'Figtree', 'Big Shoulders', 'Syne', 'JetBrains', 'googleapis', 'gstatic'] as $gone) {
                $this->assertStringNotContainsString($gone, $text, "{$gone} is gone from {$file}");
            }
        }
        $this->assertStringContainsString('var(--dot-font-sans', $this->resource('resources/css/programme.css'), 'the app face is dot-ui\'s Roboto token');
    }

    public function test_the_tabular_numerals_are_for_figures_not_the_whole_body(): void
    {
        $css = $this->resource('resources/css/programme.css');
        $this->assertDoesNotMatchRegularExpression('/body\.pg\s*\{[^}]*tabular-nums/s', $css);
        $this->assertStringContainsString('.dot-nums { font-variant-numeric: tabular-nums; }', $css);
    }

    public function test_the_icon_font_is_a_small_self_hosted_subset(): void
    {
        $file = dirname(__DIR__, 2).'/resources/fonts/material-symbols-rounded-subset.woff2';
        $this->assertFileExists($file);
        $this->assertLessThan(30 * 1024, filesize($file), 'a subset of the icons used, not the 5.4 MB family');
        $css = $this->resource('resources/css/programme.css');
        $this->assertStringContainsString('material-symbols-rounded-subset.woff2', $css);
        $this->assertStringContainsString('html.pg-noicons .material-symbols-rounded { visibility: hidden; }', $css, 'a fallback if the file never loads');
    }

    // ── The header ──────────────────────────────────────────────────────────

    public function test_the_header_has_a_short_navigation_a_menu_drawer_and_no_icon_bottom_bar(): void
    {
        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<header class="dh', $html);
        $this->assertStringNotContainsString('pg-rail', $html, 'the 14-icon rail and bottom bar are gone');
        $this->assertStringNotContainsString('pg-top', $html);

        // The bar: five places and two named groups for the owner (Admin only for owners and admins).
        preg_match('/<nav class="dh-nav".*?<\/nav>/s', $html, $nav);
        foreach (['Projects', 'Portfolio', 'Calendar', 'Reports', 'Dashboards', 'Work', 'Admin'] as $label) {
            $this->assertStringContainsString('>'.$label, $nav[0], $label);
        }
        $this->assertSame(5, preg_match_all('/<li class="dh-nav__item"><a class="dh-nav__link"/', $nav[0]), 'five plain links');
        foreach (['My time', 'Workload', 'Automations', 'Integrations', 'Data and security'] as $grouped) {
            $this->assertStringContainsString($grouped, $nav[0], $grouped.' sits in a group');
        }

        // The phone menu: one button, one drawer holding the same entries, the account and the switcher stay in the bar.
        $this->assertStringContainsString('data-dh-menu-button', $html);
        $this->assertSame(1, preg_match('/<div class="dh-drawer" id="dh-drawer".*?<\/div>\s*<\/div>\s*<\/div>/s', $html, $drawer));
        foreach (['Portfolio', 'Workload', 'Integrations', 'Profile and security', 'Sign out'] as $entry) {
            $this->assertStringContainsString($entry, $drawer[0], $entry.' is reachable on a phone');
        }
        $this->assertStringContainsString('data-dsw', $html, 'the switcher stays in the bar');
        $this->assertStringContainsString('data-dh-account', $html);
    }

    public function test_the_admin_group_is_only_for_owners_and_admins(): void
    {
        $owner = $this->owner();
        $member = User::factory()->create(['current_team_id' => $owner->currentTeam->id]);
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $html = $this->actingAs($member)->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/<nav class="dh-nav".*?<\/nav>/s', $html, $nav);
        $this->assertStringNotContainsString('Integrations', $nav[0]);
        $this->assertStringNotContainsString('Data and security', $nav[0]);
    }

    public function test_the_account_menu_switches_business_with_a_put_form_and_keeps_the_theme_control(): void
    {
        $owner = $this->owner('First Co');
        $second = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Second Co', 'personal_team' => false]);
        $owner->ownedTeams()->get();

        $html = $this->actingAs($owner)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('Switch business', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*action="[^"]*current-team"[^>]*>.*?name="_method" value="PUT".*?name="team_id" value="'.$second->id.'"/s', $html);
        $this->assertStringContainsString('data-dh-theme-set', $html);
    }

    public function test_when_infodot_cannot_be_reached_the_switcher_still_shows_an_honest_degraded_state(): void
    {
        Http::preventStrayRequests();
        config(['ecosystem.infodot' => null, 'ecosystem.platforms.infodot.url' => 'https://infodot.test']);
        $owner = $this->owner();
        $owner->currentTeam->forceFill(['infodot_team_uuid' => '33333333-3333-4333-8333-333333333333'])->save();

        $html = $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-dsw', $html, 'the switcher does not vanish');
        $this->assertStringContainsString('data-state="degraded"', $html);
        $this->assertStringContainsString('Could not check your platforms just now', $html);
        $this->assertStringNotContainsString('Active', preg_replace('/<script.*?<\/script>/s', '', $html) ?? '', 'never claims a platform is active');
    }

    public function test_the_notification_bell_is_a_panel_that_loads_its_list_when_opened(): void
    {
        $user = $this->owner();
        $user->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['title' => 'Milestone due soon', 'message' => 'Go-live is tomorrow'],
        ]);

        $html = $this->actingAs($user)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('dh-bell--panel', $html);
        $this->assertStringContainsString('Notifications, 1 unread', $html);
        $this->assertStringNotContainsString('Go-live is tomorrow', $html, 'the list is not read until the bell is opened');

        Livewire::actingAs($user)->test(NotificationBell::class)
            ->assertDontSee('Go-live is tomorrow')
            ->call('load')->assertSee('Go-live is tomorrow')
            ->call('markAllAsRead')->assertDontSee('Mark all read');
    }

    // ── Search on every page ────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        return [
            'projects' => ['dashboard'], 'portfolio' => ['portfolio.index'], 'calendar' => ['calendar.index'], 'reports' => ['reporting.index'],
            'dashboards' => ['dashboards.index'], 'automations' => ['automations.index'], 'time' => ['time.index'], 'workload' => ['workload.index'],
            'notifications' => ['notifications.index'], 'templates' => ['templates.index'], 'integrations' => ['integrations.index'], 'security' => ['security.index'],
            'new project' => ['projects.create'], 'profile' => ['profile.show'], 'create team' => ['teams.create'],
        ];
    }

    /** @dataProvider pages */
    #[DataProvider('pages')]
    public function test_the_search_is_in_the_header_on_every_page_type(string $route): void
    {
        $html = $this->actingAs($this->owner())->get(route($route))->assertOk()->getContent();
        $this->assertStringContainsString('<header class="dh', $html);
        $this->assertStringContainsString('data-pg-search', $html);
        $this->assertStringContainsString('class="dsr-input"', $html);
        $this->assertStringContainsString('data-dh-search-toggle', $html, 'and its phone button');
    }

    public function test_the_search_is_in_the_header_on_project_pages_too(): void
    {
        $owner = $this->owner();
        $project = $this->project($owner, 'Kiln reline');
        foreach (['projects.show', 'projects.work', 'projects.board', 'projects.schedule', 'projects.calendar', 'projects.time', 'projects.money', 'projects.documents', 'projects.settings'] as $route) {
            $html = $this->actingAs($owner)->get(route($route, $project))->assertOk()->getContent();
            $this->assertStringContainsString('data-pg-search', $html, $route);
        }
    }

    public function test_a_guest_sees_no_header_search(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('data-pg-search', false);
    }

    public function test_the_search_asks_nothing_until_it_is_focused_and_then_finds_only_this_businesss_work(): void
    {
        $acme = $this->owner('Acme');
        $globex = $this->owner('Globex');
        $mine = $this->project($acme, 'Kiln reline');
        $mine->tasks()->create(['title' => 'Kiln bricks', 'status' => 'todo', 'priority' => 'medium']);
        $theirs = $this->project($globex, 'Kiln secret');
        $theirs->tasks()->create(['title' => 'Kiln secret task', 'status' => 'todo', 'priority' => 'medium']);

        $this->actingAs($acme);
        Livewire::test(HeaderSearch::class)->set('query', 'kiln')->assertDontSee('Kiln reline', false);
        Livewire::test(HeaderSearch::class)->call('touch')->set('query', 'kiln')
            ->assertSee('Kiln reline')->assertSee('Kiln bricks')->assertDontSee('secret');

        $this->actingAs($globex);
        Livewire::test(HeaderSearch::class)->call('touch')->set('query', 'kiln')
            ->assertSee('Kiln secret')->assertDontSee('Kiln reline')->assertDontSee('Kiln bricks');
    }

    public function test_the_search_hides_private_projects_from_those_not_on_them_and_pages_the_role_may_not_open(): void
    {
        $owner = $this->owner();
        $this->project($owner, 'Kiln open');
        $this->project($owner, 'Kiln hidden', 'private');
        $colleague = User::factory()->create(['current_team_id' => $owner->currentTeam->id]);
        $owner->currentTeam->users()->attach($colleague, ['role' => 'editor']);

        $this->actingAs($owner);
        Livewire::test(HeaderSearch::class)->call('touch')->set('query', 'kiln')->assertSee('Kiln open')->assertSee('Kiln hidden');

        $this->actingAs($colleague);
        Livewire::test(HeaderSearch::class)->call('touch')->set('query', 'kiln')->assertSee('Kiln open')->assertDontSee('Kiln hidden');
        Livewire::test(HeaderSearch::class)->call('touch')->set('query', 'security')->assertDontSee('Data and security');
    }

    public function test_the_search_states_empty_no_match_and_a_wildcard_is_text(): void
    {
        $owner = $this->owner();
        $this->project($owner, 'Kiln reline');
        $this->actingAs($owner);

        Livewire::test(HeaderSearch::class)->call('touch')->assertSee('Projects')->assertSee('Go to');
        Livewire::test(HeaderSearch::class)->call('touch')->set('query', 'zzzzqq')->assertSee('Nothing you can open matches');
        Livewire::test(HeaderSearch::class)->call('touch')->set('query', '%')->assertSee('Nothing you can open matches');
        Livewire::test(HeaderSearch::class)->call('touch')->set('query', str_repeat('k', 500))->assertSee('Nothing you can open matches');
    }

    public function test_a_person_with_no_business_gets_nothing_not_an_error(): void
    {
        $user = User::factory()->create(['current_team_id' => null]);
        $this->actingAs($user);

        Livewire::test(HeaderSearch::class)->call('touch')->set('query', 'kiln')->assertSee('Nothing you can open matches');
    }
}
