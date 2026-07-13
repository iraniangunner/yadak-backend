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

    'kavenegar' => [
        'api_key' => env('KAVENEGAR_API_KEY'),
    ],

    'passport' => [
        'password_client_id' => env('PASSPORT_PASSWORD_GRANT_CLIENT_ID'),
        'password_client_secret' => env('PASSPORT_PASSWORD_GRANT_CLIENT_SECRET'),
    ],

    'zarinpal' => [
        'merchant_id' => env('ZARINPAL_MERCHANT_ID', '00000000-0000-0000-0000-000000000000'),
        'sandbox' => env('ZARINPAL_SANDBOX', true),
    ],

    'sales_alert' => [
        'tolerance_percent' => env('SALES_ALERT_TOLERANCE_PERCENT', 50),
        'period_days' => env('SALES_ALERT_PERIOD_DAYS', 7),
    ],

    'inventory_alert' => [
        'stale_order_hours' => env('INVENTORY_ALERT_STALE_HOURS', 24),
    ],

    'snappbox' => [
        'api_key' => env('SNAPPBOX_API_KEY'),
        'base_url' => env('SNAPPBOX_BASE_URL', 'https://api.snappbox.ir'),
    ],

    'tipax' => [
        'api_key' => env('TIPAX_API_KEY'),
        'base_url' => env('TIPAX_BASE_URL', 'https://api.tipax.ir'),
    ],

    'post_pishtaz' => [
        'api_key' => env('POST_PISHTAZ_API_KEY'),
        'base_url' => env('POST_PISHTAZ_BASE_URL', 'https://api.post.ir'),
    ],

    'sepidar' => [
        'base_url' => env('SEPIDAR_BASE_URL', 'http://localhost:7373/api'),
        'integration_id' => env('SEPIDAR_INTEGRATION_ID'),
        'cached_token' => env('SEPIDAR_CACHED_TOKEN'), // موقتی؛ باید با فرآیند واقعی توکن جایگزین بشه
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

];
