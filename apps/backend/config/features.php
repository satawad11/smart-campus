<?php

/*
|--------------------------------------------------------------------------
| Deployment Kill Switches for Locked V1 Features (SEC-024 / PILOT-001)
|--------------------------------------------------------------------------
|
| SEC-024 approved Production V1 on the condition that FEATURE_DOOR_ENABLED,
| FEATURE_VISITOR_ENABLED, FEATURE_VEHICLE_ENABLED, FEATURE_ALPR_ENABLED and
| FEATURE_AI_ASSISTANT_ENABLED stay explicitly `false` in production config.
|
| These values are an environment-level ceiling on top of the database
| `feature.*` system settings (SEC-002): when a variable is set to a false
| value, the feature is disabled regardless of the system setting, so an
| administrator cannot switch a locked module on through the settings API.
| An unset variable (null) leaves the SEC-002 settings behaviour unchanged,
| which itself fails closed. `php artisan pilot:preflight` fails unless every
| variable below is explicitly false.
|
*/

return [

    'locked_kill_switches' => [
        'door' => env('FEATURE_DOOR_ENABLED'),
        'visitor' => env('FEATURE_VISITOR_ENABLED'),
        'vehicle' => env('FEATURE_VEHICLE_ENABLED'),
        'alpr' => env('FEATURE_ALPR_ENABLED'),
        'ai_assistant' => env('FEATURE_AI_ASSISTANT_ENABLED'),
    ],

];
