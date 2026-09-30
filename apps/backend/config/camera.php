<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Camera Connection & Snapshot Configuration (CAM-002)
    |--------------------------------------------------------------------------
    |
    | Bounded timeouts for the connection test and snapshot capture. Real RTSP
    | frame acquisition is CAM-005/006/007 (VideoSource/RTSP source/frame
    | sampler); until that lands, the snapshot provider is a mock that never
    | fabricates a real camera frame.
    |
    */
    'connection_test' => [
        'timeout_seconds' => env('CAMERA_CONNECTION_TEST_TIMEOUT', 3),
    ],

    'snapshot' => [
        // 'mock' (default, safe placeholder) — a real provider is wired in
        // once CAM-005/006/007 exist.
        'provider' => env('CAMERA_SNAPSHOT_PROVIDER', 'mock'),
        'timeout_seconds' => env('CAMERA_SNAPSHOT_TIMEOUT', 5),
    ],

    // system:monitor raises CAMERA_DOWN when an ACTIVE camera has sent no
    // health report for this long (SEC-013 table: "> 2 minutes no frames").
    'health' => [
        'stale_after_seconds' => env('CAMERA_STALE_AFTER_SECONDS', 120),
    ],
];
