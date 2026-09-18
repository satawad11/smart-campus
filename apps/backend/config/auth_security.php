<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Security Policy (AUTH-004)
    |--------------------------------------------------------------------------
    |
    | Configuration values for account lockout and failed login attempt limits.
    | These values can be overridden via environment variables or loaded from
    | System Settings in later modules.
    |
    */

    'max_failed_attempts' => (int) env('AUTH_MAX_FAILED_ATTEMPTS', 5),

    'lock_minutes' => (int) env('AUTH_LOCK_MINUTES', 15),

];
