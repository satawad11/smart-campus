<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Node Health Thresholds (CAM-014)
    |--------------------------------------------------------------------------
    |
    | Heartbeat classification (DEGRADED bounds) and the staleness window
    | used by the ai-nodes:detect-stale command to flag a node OFFLINE when
    | no heartbeat has arrived recently. A live heartbeat call is never
    | itself classified OFFLINE — only the separate staleness sweep can do
    | that, since silence (no call at all) is what OFFLINE actually means.
    |
    */
    'health' => [
        'degraded_cpu_usage' => env('AI_NODE_DEGRADED_CPU_USAGE', 90.0),
        'degraded_ram_usage' => env('AI_NODE_DEGRADED_RAM_USAGE', 90.0),
        'degraded_gpu_usage' => env('AI_NODE_DEGRADED_GPU_USAGE', 95.0),
        'degraded_temperature' => env('AI_NODE_DEGRADED_TEMPERATURE', 85.0),
        'degraded_queue_depth' => env('AI_NODE_DEGRADED_QUEUE_DEPTH', 100),
        // COM-03: the event buffer and logs live on the node disk.
        'degraded_disk_usage' => env('AI_NODE_DEGRADED_DISK_USAGE', 90.0),

        'stale_after_seconds' => env('AI_NODE_STALE_AFTER_SECONDS', 90),
    ],

    // COM-03 remote commands: an unfinished command expires after this.
    'commands' => [
        'ttl_seconds' => env('AI_NODE_COMMAND_TTL_SECONDS', 300),
    ],

    // COM-03e one-time enrollment codes: lifetime and per-IP attempt limit
    // on the unauthenticated exchange endpoint (a 60-bit code plus this
    // limit keeps guessing infeasible).
    'enrollment' => [
        'code_ttl_minutes' => env('AI_NODE_ENROLLMENT_CODE_TTL_MINUTES', 30),
        'attempts_per_minute' => env('AI_NODE_ENROLLMENT_ATTEMPTS_PER_MINUTE', 10),
    ],
];
