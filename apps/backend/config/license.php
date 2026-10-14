<?php

/*
|--------------------------------------------------------------------------
| Commercial license verification (COM-02)
|--------------------------------------------------------------------------
|
| Licenses are Ed25519-signed by the vendor's offline issuer
| (tools/license-issuer). This instance only ever holds PUBLIC keys; the
| private signing key never enters this repository, the frontend bundle or
| an AI-node image. See docs/commercial/LICENSE_MODEL.md.
|
*/

$vendorKeys = [
    // key_id => base64 (standard alphabet) Ed25519 public key (32 bytes).
    // Production keys are added here by the vendor release process only.

    // Generated 2026-10-14 with tools/license-issuer (owner decision). The
    // private key is NOT in this repository; custody: docs/commercial/LICENSE_MODEL.md §6.
    'vendor-2026-a' => '2DxHHcHvoEgU/TF/tsyssHA+Ur8KbO2WhYV4QbrAfrg=',
];

// Extra trusted keys from the environment ("kid:base64key;kid2:base64key")
// are honoured outside production only, for development and staging
// rehearsals. A production instance trusts the keys shipped in this file.
$envKeys = [];
if (env('APP_ENV') !== 'production') {
    foreach (array_filter(explode(';', (string) env('LICENSE_TRUSTED_KEYS', ''))) as $pair) {
        [$kid, $key] = array_pad(explode(':', trim($pair), 2), 2, '');
        if ($kid !== '' && $key !== '') {
            $envKeys[$kid] = $key;
        }
    }
}

return [

    'trusted_keys' => array_merge($vendorKeys, $envKeys),

    /*
    | off     — license state is reported only.
    | warn    — also logged and alerted (license:check); nothing is blocked.
    | enforce — growth beyond the license is refused (new or re-activated
    |           cameras and AI nodes, new face enrollments). Existing data,
    |           monitoring, review, reports, exports, audit and health are
    |           never blocked and nothing is ever deleted.
    |
    | Default warn: the human-approved V1 pilot keeps its behaviour until an
    | owner opts in. Commercial installations set LICENSE_ENFORCEMENT=enforce.
    */
    'enforcement' => env('LICENSE_ENFORCEMENT', 'warn'),

    // Days before expiry from which the state reads EXPIRING and alerts start.
    'expiry_warning_days' => (int) env('LICENSE_EXPIRY_WARNING_DAYS', 30),

    // A clock more than this far behind the last validation time is reported
    // as clock rollback; expiry is always evaluated against the later time.
    'clock_rollback_tolerance_hours' => (int) env('LICENSE_CLOCK_ROLLBACK_TOLERANCE_HOURS', 24),

];
