<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Upload malware scan (SEC-NEXT)
    |--------------------------------------------------------------------------
    |
    | Every uploaded file (student photo, student CSV import, face enrollment
    | and recognition-test images) is scanned before the application reads or
    | stores it.
    |
    | driver: "off" (default: development, or a site without the scanner) or
    |         "clamav" (clamd over TCP; docker-compose.pilot.yml profile
    |         "clamav" runs it). Recommended for every real installation.
    | fail_closed: when the scanner cannot give a verdict (down, timeout,
    |         file over its size limit) the upload is refused with 503
    |         UPLOAD_SCAN_UNAVAILABLE. Turning this off accepts unscanned
    |         files and raises an alert instead.
    |
    */
    'driver' => env('UPLOAD_SCAN_DRIVER', 'off'),

    'fail_closed' => (bool) env('UPLOAD_SCAN_FAIL_CLOSED', true),

    'clamav' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout_seconds' => (float) env('CLAMAV_TIMEOUT_SECONDS', 30),
    ],
];
