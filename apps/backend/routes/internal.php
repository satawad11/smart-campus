<?php

use App\Http\Controllers\Internal\AiNodeInternalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Internal Service API (/internal/v1)
|--------------------------------------------------------------------------
|
| Machine-to-machine only (PROJECT_RULES §6). No cookies/session/CSRF; auth
| is the internal.token shared-secret middleware (CAM-003), to be replaced
| by per-node credentials without changing this route contract (CAM-004).
| Never mix with the human-session /api/v1 middleware stack.
|
*/
Route::middleware('internal.token')->group(function () {
    Route::post('/ai-nodes/register', [AiNodeInternalController::class, 'register']);
    Route::post('/ai-nodes/heartbeat', [AiNodeInternalController::class, 'heartbeat']);
});
