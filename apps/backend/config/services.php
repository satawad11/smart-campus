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

    // Internal AI perception service (FastAPI). Used for health checks (FND-010)
    // and, later, recognition calls over the internal network.
    'ai' => [
        'url' => env('AI_SERVICE_URL', 'http://ai-service:8001'),
    ],

    // Shared secret gating /internal/v1/* machine-to-machine endpoints
    // (CAM-003). Temporary: CAM-004 replaces this with per-node issued
    // credentials; the internal route/middleware shape will not need to
    // change. Fails closed — an unset token means the internal API is
    // unreachable, never that it is open.
    'internal' => [
        'token' => env('INTERNAL_SERVICE_TOKEN'),
    ],

];
