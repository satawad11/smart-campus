<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Recognition Event Deduplication (EVT-005)
    |--------------------------------------------------------------------------
    |
    | Two cooldown windows, distinct from EVT-004's exact-event_uuid
    | idempotency: these suppress a *new*, genuinely-distinct event_uuid
    | that still represents the same real-world occurrence as one just
    | ingested (docs/09 "Deduplication: semantic cooldown camera+person;
    | unknown cooldown camera+track") — e.g. a track lost and immediately
    | re-acquired produces a new track_id/event_uuid on the AI-node side,
    | but is still the same person standing in front of the same camera.
    |
    */
    'semantic_cooldown_seconds' => env('EVENT_SEMANTIC_COOLDOWN_SECONDS', 30),
    'unknown_cooldown_seconds' => env('EVENT_UNKNOWN_COOLDOWN_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Event face crop (EVT-025, ACR-EVT-24 approved 2026-11-20)
    |--------------------------------------------------------------------------
    |
    | An AI node may attach one bounded JPEG face crop to an UNKNOWN / REVIEW /
    | DENY event it just delivered. Stored only on the private event-snapshots
    | disk; never returned in ordinary event JSON. Retention follows the
    | retention.snapshots_days setting. No full frames, no embeddings.
    |
    */
    'snapshot' => [
        'disk' => env('EVENT_SNAPSHOT_DISK', 'event_snapshots'),
        'max_bytes' => (int) env('EVENT_SNAPSHOT_MAX_BYTES', 262144),
        // A face crop, not a camera frame: both sides must fit this bound.
        'max_dimension' => (int) env('EVENT_SNAPSHOT_MAX_DIMENSION', 640),
        'min_dimension' => (int) env('EVENT_SNAPSHOT_MIN_DIMENSION', 32),
        // The crop must arrive soon after the event; it cannot be attached later.
        'upload_window_seconds' => (int) env('EVENT_SNAPSHOT_UPLOAD_WINDOW_SECONDS', 600),
    ],
];
