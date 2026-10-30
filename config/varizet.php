<?php

/*
 * Varizet («واریزت») for Laravel. Publish with:
 *   php artisan vendor:publish --tag=varizet-config
 */

return [
    // vz_test_… for the sandbox, vz_live_… in production (never commit either).
    'api_key' => env('VARIZET_API_KEY'),

    // API origin; the dev stack is http://localhost:4000.
    'base_url' => env('VARIZET_API_BASE_URL', 'https://api.varizet.ir'),

    'timeout' => (int) env('VARIZET_TIMEOUT', 15),
    'max_retries' => (int) env('VARIZET_MAX_RETRIES', 2),

    'webhook' => [
        // Signing secret of the endpoint (whsec_…), shown once by the panel or
        // `php artisan varizet:webhook:register`.
        'secret' => env('VARIZET_WEBHOOK_SECRET'),

        // After `rotate_secret`, keep the old secret here for 24 hours.
        'previous_secret' => env('VARIZET_WEBHOOK_PREVIOUS_SECRET'),

        // Seconds a signature stays valid (docs/05 §6).
        'tolerance' => 300,

        // The package registers POST /{path} named `varizet.webhook` unless disabled.
        'route' => env('VARIZET_WEBHOOK_ROUTE', true),
        'path' => env('VARIZET_WEBHOOK_PATH', 'varizet/webhook'),

        // Extra middleware for the route (the signature middleware is always first). The route is
        // outside the `web` group, so no session and no CSRF token is needed.
        'middleware' => [],

        // What `varizet:webhook:register` subscribes to.
        'events' => [
            'order.paid',
            'order.needs_review',
            'order.underpaid',
            'order.expired',
            'order.canceled',
            'refund.created',
        ],
    ],

    // One-click connect: `php artisan varizet:connect` prints the consent URL; after approval the
    // browser comes back to GET /{callback_path} (named `varizet.connect.callback`), which needs the
    // same cache as the CLI (file/redis/database, not `array`).
    'connect' => [
        // The Varizet panel; the dev stack is http://app.varizet.local:8080.
        'app_url' => env('VARIZET_APP_URL', 'https://app.varizet.ir'),
        'route' => env('VARIZET_CONNECT_ROUTE', true),
        'callback_path' => 'varizet/connect/callback',
        'middleware' => [],
    ],
];
