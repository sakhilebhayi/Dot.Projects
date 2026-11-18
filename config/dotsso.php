<?php

use App\Sso\ProjectsProvisioner;

/*
 * Dot.Projects' own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false), comes from the package's defaults and the host .env.
 */
return [
    'provisioner' => ProjectsProvisioner::class,

    // Projects has always created an account for a person InfoDot vouches for (INFODOT_PROVISION_USERS), so that stays.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', env('INFODOT_PROVISION_USERS', true)),

    'landing' => '/dashboard',

    // A deep link may open any signed-in page of the app, and nothing else.
    'deep_link' => [
        'allow' => ['#^/(dashboard|notifications|time|workload|portfolio|reports|dashboards|automations|integrations|data-and-security|templates|calendar|projects|user/profile|teams|team-invitations)(/|\?|$)#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm)(/|\?|$)#i'],
    ],

    // Never intercepted beyond the package's own exclusions: the Sanctum API, /guardian/health (bearer), /up, /ready and
    // /csp-report are not login pages. Nothing extra to add.
    'allow_paths' => [],
];
