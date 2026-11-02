<?php

return [
    /*
    | Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. 0 turns it off. Subdomains and preload are deliberately
    | left out: many sibling platforms share the parent domain and each sets its own. 15552000 is 180 days.
    */
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),

    /*
    | The Content-Security-Policy is ENFORCED (nonce-based, R-34 P12a). SECURITY_CSP_REPORT_ONLY=true turns it back into a
    | report-only policy without a deploy: the kill-switch if a real page turns out to need something the policy blocks.
    */
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),
];
