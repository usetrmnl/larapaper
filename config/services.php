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

    'trmnl' => [
        'base_url' => 'https://trmnl.com',
        'proxy_base_url' => env('TRMNL_PROXY_BASE_URL', 'https://trmnl.app'),
        'proxy_refresh_minutes' => env('TRMNL_PROXY_REFRESH_MINUTES', 15),
        'proxy_refresh_cron' => env('TRMNL_PROXY_REFRESH_CRON'),
        'override_orig_icon' => env('TRMNL_OVERRIDE_ORIG_ICON', false),
        'image_url_timeout' => env('TRMNL_IMAGE_URL_TIMEOUT', 30), // 30 seconds; increase on low-powered devices
        'liquid_enabled' => env('TRMNL_LIQUID_ENABLED', false),
        'liquid_path' => env('TRMNL_LIQUID_PATH', '/usr/local/bin/trmnl-liquid-cli'),
    ],

    'webhook' => [
        'notifications' => [
            'url' => env('WEBHOOK_NOTIFICATION_URL', null),
            'topic' => env('WEBHOOK_NOTIFICATION_TOPIC', 'null'),
        ],
    ],

    'oidc' => [
        'enabled' => env('OIDC_ENABLED', false),
        // OIDC_ENDPOINT can be either:
        // - Base URL: https://your-provider.com (will append /.well-known/openid-configuration)
        // - Full well-known URL: https://your-provider.com/.well-known/openid-configuration
        'endpoint' => env('OIDC_ENDPOINT'),
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'redirect' => env('APP_URL', 'http://localhost:8000').'/auth/oidc/callback',
        'scopes' => explode(',', env('OIDC_SCOPES', 'openid,profile,email')),
    ],

    'transform_runner' => [
        'url'     => env('TRANSFORM_RUNNER_URL'),
        'timeout' => (int) env('TRANSFORM_TIMEOUT_SECONDS', 30),
    ],

];
