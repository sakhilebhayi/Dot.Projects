<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response. A header the route already set is left alone.
 *
 * X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy and (HTTPS requests only)
 * Strict-Transport-Security. HSTS covers this host alone: includeSubDomains and preload are left out on purpose
 * because many sibling platforms live on neighbouring subdomains and each sets its own.
 *
 * The Content-Security-Policy is ENFORCED and nonce-based (R-34 P12a), the shape InfoDot (R-19) and Dot.HR run
 * live: a per-request nonce is registered with Vite before the views render, so @vite tags and Livewire's
 * assets carry it (Livewire's FrontendAssets falls back to Vite::cspNonce()), and the three hand-written inline
 * scripts (the theme-before-paint snippets and the welcome page's menu) declare it. 'unsafe-inline' is gone
 * from script-src and script-src-attr is 'none': no view uses on*= handlers or javascript: links.
 * 'unsafe-eval' REMAINS as a stack requirement: Livewire 3's bundled Alpine evaluates its expressions with the
 * Function constructor. style-src keeps 'unsafe-inline' because The Programme's views use style attributes
 * (schedule bars, roadmap marks); script injection is the risk this closes. Every origin below is one the views
 * or the framework actually load:
 *
 * - https://fonts.googleapis.com (style-src) and https://fonts.gstatic.com (font-src): Google Fonts and
 *   Material Symbols, linked from every layout and the error pages.
 * - https://images.unsplash.com (img-src): the welcome page's photographs.
 * - https://ui-avatars.com (img-src): Jetstream's default avatar on the team pages.
 *
 * Violations report to /csp-report. SECURITY_CSP_REPORT_ONLY=true in .env is the kill-switch back to
 * report-only without a deploy.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Registered before the views render, so every tag that needs it carries the value the header announces.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Reporting-Endpoints' => 'csp="'.url('/csp-report').'"',
            (config('security.csp_report_only', false) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy') => $this->policy($nonce),
        ];

        if ($request->isSecure() && (int) config('security.hsts_seconds', 0) > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.(int) config('security.hsts_seconds');
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        // Local development loads assets from the Vite server (http: and its ws: hot reload).
        $dev = app()->isLocal() ? ' http://localhost:* http://127.0.0.1:*' : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'{$dev}",
            "script-src-attr 'none'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com{$dev}",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob: https://images.unsplash.com https://ui-avatars.com",
            // Reverb websockets are wss: in production; local development talks plaintext ws:.
            "connect-src 'self' wss: https:".(app()->isLocal() ? ' ws: http:' : ''),
            "frame-src 'self'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            // Sign-in hands over to InfoDot over https; every other form posts here.
            "form-action 'self' https:",
            'report-uri /csp-report',
            'report-to csp',
        ]);
    }
}
