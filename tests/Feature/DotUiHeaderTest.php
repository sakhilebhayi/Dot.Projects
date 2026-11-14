<?php

namespace Tests\Feature;

use App\Livewire\CommandPalette;
use App\Livewire\Projects\WorkList;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-51: the header carries the shared Dot UI (dot-ui): the search pill and the platform switcher. Presentation changed;
 * the command palette behind the pill (its results, permissions and tenant isolation) did not, and the switcher only
 * ever shows what InfoDot says the CURRENT company holds, linking through InfoDot's /go (never straight to a host, never
 * with a token).
 */
class DotUiHeaderTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosystem.infodot' => ['redeem_url' => self::REDEEM, 'platform' => 'projects', 'secret' => 'a-test-secret', 'timeout' => 2, 'provision' => true, 'entitlement_check' => true],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** InfoDot's read endpoint, as it answers this platform about one company. */
    private static function body(string $uuid, array $held = ['projects', 'files', 'infodot']): array
    {
        $rows = [];
        foreach (['projects' => 'Dot.Projects', 'files' => 'Dot.Files', 'finance' => 'Dot.Finance', 'hr' => 'Dot.HR', 'infodot' => 'InfoDot', 'press' => 'Dot.Press'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'status' => 'active', 'entitled' => in_array($key, $held, true), 'is_core' => false, 'access_mode' => 'everyone'];
        }

        return ['platform' => 'projects', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => $rows];
    }

    private function fakeInfoDot(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function memberOf(string $uuid, string $name): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    /** The markup of the switcher only, so the assertions cannot be satisfied by the rest of the page. */
    private function switcherHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $m), 'the header carries the switcher');

        return $m[0];
    }

    // ── The search pill ──────────────────────────────────────────────────────

    public function test_the_header_search_is_the_shared_pill_and_only_opens_the_command_palette(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('class="pg-top-search"', $html);
        $this->assertStringContainsString('class="dsr-input"', $html, 'the shared pill');
        $this->assertSame(1, preg_match('/<form [^>]*data-cmd-open[^>]*>/', $html, $form), 'the pill form carries the palette trigger');
        $this->assertStringNotContainsString('action=', $form[0], 'it submits nowhere: app.js opens the palette instead');
        $this->assertMatchesRegularExpression('/<input[^>]*\breadonly\b[^>]*>/', $html, 'the pill is a trigger, not a second search box');
        $this->assertStringContainsString('aria-keyshortcuts="Control+K Meta+K"', $html);
        $this->assertStringContainsString('Ctrl K', $html, 'the hint is true: Ctrl or Cmd and K opens the palette');
        $this->assertStringNotContainsString('data-dsr-shortcut-script', $html);
        $this->assertStringNotContainsString('dot-ui/js/dot-search.js', $html, 'the pill does not load the shared shortcut: the palette owns Ctrl K');
        $this->assertStringNotContainsString('pg-cmd-open', $html, 'the old Search chip is gone');
        $this->assertSame(1, substr_count($html, 'data-cmd-open'), 'one trigger');
        $this->assertSame(1, substr_count($html, '<dialog class="pg-cmd" data-cmd'), 'the palette itself is unchanged');
    }

    public function test_the_palette_behind_the_pill_still_finds_only_the_current_businesss_work(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME)),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX)),
        ]);
        $acme = $this->memberOf(self::ACME, 'Acme Ltd');
        $globex = $this->memberOf(self::GLOBEX, 'Globex Ltd');
        Project::create(['team_id' => $acme->currentTeam->id, 'owner_id' => $acme->id, 'name' => 'Kiln reline', 'status' => 'active']);
        Project::create(['team_id' => $globex->currentTeam->id, 'owner_id' => $globex->id, 'name' => 'Kiln reline secret', 'status' => 'active']);

        $this->actingAs($acme);
        Livewire::test(CommandPalette::class)
            ->set('open', true)->set('q', 'Kiln')
            ->assertSee('Kiln reline')->assertDontSee('secret');

        $this->actingAs($globex);
        Livewire::test(CommandPalette::class)
            ->set('open', true)->set('q', 'Kiln')
            ->assertSee('Kiln reline secret');
    }

    public function test_a_guest_sees_no_search_and_no_switcher(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('dsr-input', false)->assertDontSee('data-dsw', false);
    }

    // ── The compact filters ─────────────────────────────────────────────────

    public function test_the_projects_filter_is_the_shared_compact_filter_with_the_same_form_and_parameters(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');
        Project::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => 'Kiln reline', 'status' => 'active']);
        Project::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => 'Conveyor', 'status' => 'active']);

        $html = $this->actingAs($owner)->get(route('dashboard', ['search' => 'Kiln']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form method="GET" action="[^"]*dashboard#projects" class="pg-filters" role="search">/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*\bclass="dsr-filter pg-filter-search"[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*\bname="search"[^>]*\bvalue="Kiln"/', $html, 'same parameter, current value kept');
        $this->assertStringContainsString('id="dash-search"', $html);
        $this->assertStringContainsString('aria-label="Search projects by name"', $html);
        $this->assertSame(1, preg_match('/<tbody.*<\/tbody>/s', $html, $rows));
        $this->assertStringContainsString('Kiln reline', $rows[0]);
        $this->assertStringNotContainsString('Conveyor', $rows[0], 'the filter still filters');
    }

    public function test_the_work_list_filter_is_the_shared_compact_filter_and_still_filters(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');
        $project = Project::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => 'Kiln reline', 'status' => 'active']);
        $this->actingAs($owner);

        $component = Livewire::test(WorkList::class, ['project' => $project]);
        $html = $component->html();

        $this->assertMatchesRegularExpression('/<input[^>]*\bclass="dsr-filter pg-filter-search"[^>]*>/', $html);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="search"', $html);
        $this->assertStringContainsString('aria-label="Find a task"', $html);
        $component->set('search', 'zzz')->assertSet('search', 'zzz');
    }

    // ── The switcher ────────────────────────────────────────────────────────

    public function test_a_member_sees_the_switcher_with_what_their_company_holds_and_the_current_platform_marked(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="ok"', $panel);
        $this->assertStringContainsString('data-platform="projects"', $panel);
        $this->assertStringContainsString('Acme Ltd', $panel, 'the sentence names the company');

        $this->assertSame(1, preg_match('/<div class="dsw-bay is-current" aria-current="page">.*?You are here/s', $panel, $here));
        $this->assertStringContainsString('Dot.Projects', $here[0]);

        $this->assertStringContainsString('href="https://infodot.test/go/files?company='.self::ACME.'"', $panel);
        $this->assertStringContainsString('href="https://infodot.test/home"', $panel);
        $this->assertStringNotContainsString('/go/hr', $panel);
        $this->assertStringNotContainsString('/go/press', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms/hr"', $panel);
        $this->assertStringContainsString('Not switched on (3)', $panel);

        $this->assertStringNotContainsString('token', strtolower($panel));
        $this->assertStringNotContainsString('a-test-secret', $html);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href, 'every way out of the switcher goes through InfoDot');
        }
    }

    public function test_the_switcher_asks_infodot_about_the_current_company_only_with_the_service_secret(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk();

        Http::assertSent(function (OutgoingRequest $request) {
            return str_contains($request->url(), '/companies/'.self::ACME.'/platforms')
                && $request->hasHeader('Authorization', 'Bearer a-test-secret')
                && $request->hasHeader('X-Dot-Platform', 'projects');
        });
        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), self::GLOBEX));
    }

    public function test_two_companies_never_see_each_others_switcher(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME, ['projects', 'infodot', 'press'])),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, ['projects', 'infodot', 'hr'])),
        ]);

        $acme = $this->switcherHtml($this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->getContent());
        $globex = $this->switcherHtml($this->actingAs($this->memberOf(self::GLOBEX, 'Globex Ltd'))->get(route('dashboard'))->getContent());

        $this->assertStringContainsString('/go/press?company='.self::ACME, $acme);
        $this->assertStringNotContainsString('/go/hr', $acme);
        $this->assertStringNotContainsString(self::GLOBEX, $acme);
        $this->assertStringContainsString('/go/hr?company='.self::GLOBEX, $globex);
        $this->assertStringNotContainsString('/go/press', $globex);
        $this->assertStringNotContainsString(self::ACME, $globex);
    }

    public function test_when_infodot_cannot_be_reached_the_switcher_says_so_and_never_shows_active(): void
    {
        // The entitlement check and the switcher both get a 503: the check carries on (as it always did), the switcher degrades.
        $this->fakeInfoDot([self::url(self::ACME) => Http::response('down', 503)]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="degraded"', $panel);
        $this->assertStringContainsString('Could not check your platforms just now', $panel);
        $this->assertStringContainsString('opened through InfoDot', $panel);
        $this->assertStringNotContainsString('Active', $panel);
        $this->assertStringContainsString('/go/files', $panel, 'the core set still leaves through InfoDot, which decides again');
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href);
        }
    }

    public function test_a_connection_failure_never_breaks_the_page(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => fn () => throw new ConnectionException('refused')]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-state="degraded"', $html);
    }

    public function test_a_personal_workspace_gets_the_honest_sentence_and_infodot_is_not_asked(): void
    {
        $this->fakeInfoDot([]);
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="personal"', $panel);
        $this->assertStringContainsString('dsw-personal', $panel);
        $this->assertStringNotContainsString('dsw-bay', $panel, 'no platforms are listed for a workspace that is not a company');
        $this->assertStringContainsString('href="https://infodot.test/platforms"', $panel, 'the way to InfoDot is kept');
        Http::assertNothingSent();
    }

    public function test_the_landing_no_longer_has_the_two_link_back_nav_because_the_switcher_replaces_it(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('pg-arrive-back', $html);
        $this->assertStringNotContainsString('aria-label="Dot ecosystem"', $html);
        $this->assertStringContainsString('data-dsw', $html);
        $this->assertStringContainsString('href="https://infodot.test/home"', $html, 'InfoDot stays one click away, from the switcher');
        $this->assertStringContainsString('See all platforms', $html);
    }

    // ── CSP: self-hosted, nothing inline, nothing external ──────────────────

    public function test_the_shared_assets_are_served_from_this_site_and_resolve(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $response = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk();
        $html = $response->getContent();

        foreach (['css/dot-fonts.css', 'css/dot-tokens.css', 'css/dot-search.css', 'css/dot-switcher-fonts.css', 'css/dot-switcher.css', 'js/dot-switcher.js'] as $asset) {
            $this->assertStringContainsString(asset('dot-ui/'.$asset), $html, $asset.' is linked from this site');
            $this->assertFileExists(public_path('dot-ui/'.$asset));
        }

        foreach (glob(public_path('dot-ui/css/*.css')) as $css) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', (string) file_get_contents($css), $urls);
            foreach ($urls[1] as $url) {
                $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, basename($css).' reaches outside this site');
                $this->assertFileExists(dirname($css).'/'.$url, basename($css).' -> '.$url);
            }
        }

        preg_match_all('/<img src="([^"]+)" alt="Dot\./', $this->switcherHtml($html), $imgs);
        $this->assertNotEmpty($imgs[1]);
        foreach ($imgs[1] as $src) {
            $this->assertStringStartsWith('/dot-ui/img/platforms/', $src);
            $this->assertFileExists(public_path($src));
        }

        // The script is an external file from this origin and carries the page's CSP nonce; the policy gained no origin.
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertSame(1, preg_match("/'nonce-([^']+)'/", $policy, $nonce));
        $this->assertStringContainsString('<script src="'.asset('dot-ui/js/dot-switcher.js').'" defer nonce="'.$nonce[1].'"></script>', $html);
        preg_match_all('#https?://[^\s;]+#', $policy, $origins);
        $this->assertEqualsCanonicalizing(['https://fonts.googleapis.com', 'https://fonts.gstatic.com', 'https://images.unsplash.com', 'https://ui-avatars.com'], $origins[0]);
    }

    public function test_the_shared_markup_has_no_inline_style_or_script(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->getContent();

        $this->assertSame(1, preg_match('/<div class="pg-top-search">.*?<\/form>\s*<\/div>\s*<\/div>/s', $html, $pill));
        foreach ([$pill[0], $this->switcherHtml($html)] as $fragment) {
            $this->assertStringNotContainsString(' style=', $fragment);
            $this->assertStringNotContainsString('<style', $fragment);
            $this->assertStringNotContainsString('<script', $fragment);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $fragment, 'no inline event handlers');
        }
    }
}
