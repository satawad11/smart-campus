<?php

return [
    /*
    |--------------------------------------------------------------------------
    | RMS API Center v1 (read-only student/staff source)
    |--------------------------------------------------------------------------
    |
    | The college's RMS exposes staff, groups (classes), students and
    | timetables as JSON (docs/integrations/RMS_API_CENTER.md). The key is
    | issued by the RMS administrator, is sent only in the Authorization
    | header, and must stay in .env: never in git, logs or the browser.
    | RMS also restricts callers by IP: the server's address must be on its
    | allow-list.
    |
    */
    'url' => env('RMS_API_URL', 'https://rms.dmtc.ac.th/api_center.php'),

    'key' => env('RMS_API_KEY'),

    'timeout_seconds' => (int) env('RMS_API_TIMEOUT_SECONDS', 30),

    // Items per page (RMS allows 1-500).
    'page_limit' => 500,

    // Longest Retry-After (seconds) honoured on HTTP 429 before giving up.
    'max_retry_after_seconds' => (int) env('RMS_API_MAX_RETRY_AFTER_SECONDS', 60),
];
