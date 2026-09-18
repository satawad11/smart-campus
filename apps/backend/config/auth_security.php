<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Security Policy (AUTH-004 / AUTH-005)
    |--------------------------------------------------------------------------
    |
    | Configuration values for account lockout, failed login attempt limits,
    | and MFA challenge expiration.
    |
    */

    'max_failed_attempts' => (int) env('AUTH_MAX_FAILED_ATTEMPTS', 5),

    'lock_minutes' => (int) env('AUTH_LOCK_MINUTES', 15),

    'password_min_length' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 12),

    'password_max_length' => (int) env('AUTH_PASSWORD_MAX_LENGTH', 128),

    'mfa_challenge_minutes' => (int) env('AUTH_MFA_CHALLENGE_MINUTES', 5),

    'mfa_setup_minutes' => (int) env('AUTH_MFA_SETUP_MINUTES', 10),

    'mfa_issuer' => env('AUTH_MFA_ISSUER', 'SmartCampus'),

    'mfa_window' => (int) env('AUTH_MFA_WINDOW', 1),

    'mfa_digits' => (int) env('AUTH_MFA_DIGITS', 6),

    'mfa_period' => (int) env('AUTH_MFA_PERIOD', 30),

];
