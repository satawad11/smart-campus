<?php

/**
 * CORS Configuration (SEC-003).
 *
 * Strict CORS policy:
 * - When credentials (cookies/sessions) are enabled, wildcard origin ('*') is strictly forbidden.
 * - Allowed origins must be an explicit list configured via CORS_ALLOWED_ORIGINS.
 */
$rawOrigins = env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:3000,http://127.0.0.1:5173');
$allowedOrigins = array_filter(array_map('trim', explode(',', $rawOrigins)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => ! empty($allowedOrigins) ? $allowedOrigins : ['http://localhost:5173'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Authorization',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
        'X-Request-ID',
        'Accept',
    ],

    'exposed_headers' => [
        'X-Request-ID',
    ],

    'max_age' => 86400,

    'supports_credentials' => true,
];
