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
];
