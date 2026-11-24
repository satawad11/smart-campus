<?php

/*
|--------------------------------------------------------------------------
| Licence-plate reading (ACR-VEH-01, VEH-010)
|--------------------------------------------------------------------------
|
| ocr_min_confidence: below this OCR score a plate result is stored as
| PLATE_LOW_CONFIDENCE instead of being matched against the vehicle registry.
| PROVISIONAL: the real value is chosen with the model in VEH-007 and needs
| the owner's approval (ACR-VEH-01 D4). Until then this conservative default
| keeps doubtful reads out of the registry match.
|
| plate_cooldown_seconds: a new result for the same plate at the same camera
| within this window is treated as the same pass and not stored again.
|
*/

return [
    'ocr_min_confidence' => (float) env('ALPR_OCR_MIN_CONFIDENCE', 0.90),
    'plate_cooldown_seconds' => (int) env('ALPR_PLATE_COOLDOWN_SECONDS', 30),
];
