<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Baseline browser protections are on every response; the CSP is enforced with a nonce; a route's own header is kept. */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_carries_the_enforced_baseline_headers(): void
    {
        $r = $this->get('/');

        $r->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $permissions = (string) $r->headers->get('Permissions-Policy');
        foreach (['camera', 'microphone', 'geolocation', 'payment', 'usb'] as $feature) {
            $this->assertStringContainsString($feature.'=()', $permissions, "$feature is denied");
        }

        $this->get('/up')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/does-not-exist')->assertNotFound()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_the_csp_is_enforced_with_a_nonce_and_has_a_kill_switch(): void
    {
        $r = $this->get('/');

        $policy = (string) $r->headers->get('Content-Security-Policy');
        $this->assertFalse($r->headers->has('Content-Security-Policy-Report-Only'));
        preg_match('/script-src ([^;]+)/', $policy, $script);
        $this->assertMatchesRegularExpression("/'nonce-[A-Za-z0-9+\/=]{16,}'/", $script[1]);
        $this->assertStringNotContainsString("'unsafe-inline'", $script[1], 'no inline script runs without the nonce');
        foreach (["script-src-attr 'none'", "default-src 'self'", "frame-ancestors 'self'", "object-src 'none'", "base-uri 'self'", 'report-uri /csp-report', 'report-to csp', 'https://fonts.googleapis.com', 'https://fonts.gstatic.com'] as $part) {
            $this->assertStringContainsString($part, $policy);
        }
        $this->assertStringNotContainsString('cdn.tailwindcss.com', $policy, 'the workspace stylesheet is built, not compiled from a CDN');
        $this->assertStringContainsString('csp="', (string) $r->headers->get('Reporting-Endpoints'));
        $this->assertNotSame($policy, (string) $this->get('/')->headers->get('Content-Security-Policy'), 'a fresh nonce for every response');

        // The kill-switch turns the same policy back into a report-only one, with no deploy.
        config(['security.csp_report_only' => true]);
        $reported = $this->get('/');
        $this->assertStringContainsString("script-src-attr 'none'", (string) $reported->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertFalse($reported->headers->has('Content-Security-Policy'));
    }

    public function test_every_script_on_real_pages_carries_the_nonce_and_no_inline_handlers_exist(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = Project::create(['team_id' => $user->currentTeam->id, 'owner_id' => $user->id, 'name' => 'Kiln reline', 'status' => 'active']);
        $task = $project->tasks()->create(['title' => 'Reline', 'status' => 'todo', 'priority' => 'high']);
        $pages = ['/', '/login', route('dashboard'), route('projects.show', $project), route('projects.board', $project), route('projects.tasks.show', [$project, $task]),
            route('reporting.index'), route('automations.index'), route('dashboards.index'), route('portfolio.index'), route('projects.documents', $project)];
        foreach ($pages as $i => $url) {
            $r = $i < 2 ? $this->get($url) : $this->actingAs($user)->get($url);
            $r->assertOk();
            preg_match("/'nonce-([^']+)'/", (string) $r->headers->get('Content-Security-Policy'), $m);
            $html = (string) $r->getContent();
            preg_match_all('/<script\b[^>]*>/i', $html, $tags);
            $this->assertNotEmpty($tags[0], $url.' has scripts to check');
            foreach ($tags[0] as $tag) {
                $ok = str_contains($tag, 'nonce="'.$m[1].'"') || (preg_match('/src="([^"]+)"/', $tag, $src) && (str_starts_with($src[1], '/') || str_starts_with($src[1], url('/'))));
                $this->assertTrue($ok, $url.': a script the policy would block: '.$tag);
            }
            $this->assertDoesNotMatchRegularExpression('/\son(click|submit|change|input|load|error|key\w+|mouse\w+|focus|blur)="/i', $html, $url.' uses an inline handler, which script-src-attr blocks');
            $this->assertStringNotContainsString('javascript:', $html, $url);
        }
    }

    public function test_hsts_is_sent_on_https_only_and_can_be_switched_off(): void
    {
        $this->assertFalse($this->get('/')->headers->has('Strict-Transport-Security'), 'plain http never gets HSTS');

        $hsts = (string) $this->get('https://localhost/')->headers->get('Strict-Transport-Security');
        $this->assertSame('max-age=15552000', $hsts, 'six months, and no includeSubDomains or preload');

        config(['security.hsts_seconds' => 0]);
        $this->assertFalse($this->get('https://localhost/')->headers->has('Strict-Transport-Security'));
    }

    public function test_a_header_the_route_already_set_is_not_overwritten(): void
    {
        Route::get('/__strict', fn () => response('x')->header('X-Frame-Options', 'DENY'));

        $this->get('/__strict')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_violation_reports_are_accepted_without_a_session_and_keep_only_the_directive_and_host(): void
    {
        Cache::flush();
        Log::spy();
        $body = json_encode(['csp-report' => [
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example/steal?token=SECRET123',
            'document-uri' => 'https://example.test/x?code=SECRET456',
        ]]);

        $first = $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body);
        $first->assertNoContent();
        $this->assertEmpty($first->headers->getCookies(), 'no session or cookie is started for a report');

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body)->assertNoContent();   // the same pair is logged once a day

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => $message === 'csp.violation' && $context === ['directive' => 'script-src-elem', 'blocked_host' => 'evil.example'])
            ->once();

        // The Reporting API shape and garbage are both answered quietly.
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], json_encode([['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'img-src', 'blockedURL' => 'inline']]]))->assertNoContent();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], 'not json at all')->assertNoContent();
    }
}
