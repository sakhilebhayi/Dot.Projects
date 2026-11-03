<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Dot.Notify event ingest (R-34 P11a). Blank URL or secret: nothing is sent, nothing breaks.
    // The secret must equal NOTIFY_INGEST_SECRET_PROJECTS on the Notify server.
    'notify' => [
        'url' => env('NOTIFY_INGEST_URL'),
        'secret' => env('NOTIFY_INGEST_SECRET'),
        'platform' => env('NOTIFY_PLATFORM_KEY', 'projects'),
        'timeout' => (int) env('NOTIFY_TIMEOUT', 5),
    ],

    // Dot.Files platform file API (R-34 P11b): project documents are filed in the business's
    // Dot.Files workspace. The secret must equal DOT_SERVICE_SECRET_PROJECTS on Dot.Files.
    // Dot.Analytics' signed snapshot ingest (R-34 P11c): the business's daily delivery numbers. Off until the
    // secret is set here and the same value is ANALYTICS_INGEST_SECRET_PROJECTS on Analytics.
    'analytics' => [
        'url' => env('ANALYTICS_INGEST_URL', 'https://analytics.infodot.co.za/api/ingest/snapshots'),
        'secret' => env('ANALYTICS_INGEST_SECRET'),
        'timeout' => (int) env('ANALYTICS_TIMEOUT', 5),
    ],

    'files' => [
        'url' => env('FILES_API_URL', 'https://files.infodot.co.za'),
        'secret' => env('FILES_PLATFORM_SECRET'),
        'platform' => env('FILES_PLATFORM_KEY', 'projects'),
        'timeout' => (int) env('FILES_TIMEOUT', 20),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
    ],

];
