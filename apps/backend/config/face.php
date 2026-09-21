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

    'recognition' => [
        'enabled' => env('FACE_RECOGNITION_ENABLED', true),
        'match_threshold' => env('FACE_RECOGNITION_MATCH_THRESHOLD', 0.80),
        'review_threshold' => env('FACE_RECOGNITION_REVIEW_THRESHOLD', 0.60),
        'ambiguity_margin' => env('FACE_RECOGNITION_AMBIGUITY_MARGIN', 0.05),
    ],

    'liveness' => [
        'enabled' => env('FACE_LIVENESS_ENABLED', true),
        'provider' => env('FACE_LIVENESS_PROVIDER', 'ai_service'),
        'threshold_real' => env('FACE_LIVENESS_THRESHOLD_REAL', 0.70),
        'threshold_spoof' => env('FACE_LIVENESS_THRESHOLD_SPOOF', 0.40),
    ],
];
