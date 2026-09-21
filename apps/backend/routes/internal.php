<?php

use App\Http\Controllers\Internal\AiNodeInternalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Internal Service API (/internal/v1)
|--------------------------------------------------------------------------
|
| Machine-to-machine only (PROJECT_RULES §6). No cookies/session/CSRF; auth
| is the per-node bearer credential (ai_node.token, CAM-004). Node identity
| comes from the authenticated credential, never a client-supplied
| node_code — a credential cannot be used to impersonate a different node.
| Never mix with the human-session /api/v1 middleware stack.
|
*/
Route::middleware('ai_node.token')->group(function () {
    Route::post('/ai-nodes/register', [AiNodeInternalController::class, 'register']);
    Route::post('/ai-nodes/heartbeat', [AiNodeInternalController::class, 'heartbeat']);
});
