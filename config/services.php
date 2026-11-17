<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'pixelforge' => [
        'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
        'api_key' => env('PIXELFORGE_API_KEY'),
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

        // The safety net for missed webhooks (php artisan generations:reconcile, every minute).
        'reconcile' => [
            // Ask Pixelforge about a queued/processing generation that has not changed for this long.
            // Normal processing takes 5-90 seconds, so this is well past "slow".
            'idle_seconds' => 120,
            // Resubmit a pending generation after this long. Must exceed the submit job's retry
            // window (10 minutes), or we would resubmit one that is still legitimately retrying.
            'pending_seconds' => 720,
            // At most this many generations per run; Pixelforge allows 60 requests a minute.
            'batch_size' => 20,
        ],

        // Webhooks signed longer ago (or further ahead) than this are rejected as replays.
        'webhook_tolerance_seconds' => 300,

        // How long GET /v1/pricing is cached before it is fetched again.
        'pricing_cache_seconds' => 3600,

        // HTTP timeouts, in seconds.
        'timeout' => 10,
        'connect_timeout' => 5,

        // Seconds to wait before retrying when Pixelforge sends no usable
        // Retry-After header, and the cap applied to any wait.
        'retry_after' => [
            'rate_limit' => 10,
            'concurrency' => 5,
            'overloaded' => 2,
            'unavailable' => 5,
            'max' => 3600,
        ],
    ],

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

];
