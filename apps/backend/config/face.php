<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Face Biometrics & Recognition Configuration (FACE-011, FACE-013)
    |--------------------------------------------------------------------------
    |
    | Policy & threshold settings for biometric duplicate review and recognition.
    | FACE-011 and FACE-013 consume configured thresholds.
    | Human calibration, review, and approval of pilot threshold values are
    | strictly owned by FACE-021.
    |
    | There is NO silent hard-coded production fallback. If thresholds are
    | unconfigured, the services fail closed.
    |
    */
    'duplicate_review' => [
        'enabled' => env('FACE_DUPLICATE_REVIEW_ENABLED', true),
        'similarity_threshold' => env('FACE_DUPLICATE_REVIEW_SIMILARITY_THRESHOLD', 0.65),
    ],

    // SFace (yunet-sface/2021dec, the production model of ACR-AI-01) cosine operating
    // points. Measured on real faces scaled down to CCTV sizes (24-120 px): genuine
    // pairs 0.43-0.84, different people <= 0.29. The earlier 0.80/0.60 values were
    // placeholders that turned almost every true match into REVIEW/UNKNOWN
    // (owner decision 2026-11-23, docs/decisions/OWNER-DECISIONS-2026-11-23-CCTV-RECOGNITION.md).
    'recognition' => [
        'enabled' => env('FACE_RECOGNITION_ENABLED', true),
        'match_threshold' => env('FACE_RECOGNITION_MATCH_THRESHOLD', 0.45),
        'review_threshold' => env('FACE_RECOGNITION_REVIEW_THRESHOLD', 0.36),
        'ambiguity_margin' => env('FACE_RECOGNITION_AMBIGUITY_MARGIN', 0.05),
    ],

    'liveness' => [
        'enabled' => env('FACE_LIVENESS_ENABLED', true),
        'provider' => env('FACE_LIVENESS_PROVIDER', 'ai_service'),
        'threshold_real' => env('FACE_LIVENESS_THRESHOLD_REAL', 0.70),
        'threshold_spoof' => env('FACE_LIVENESS_THRESHOLD_SPOOF', 0.40),
    ],
];
