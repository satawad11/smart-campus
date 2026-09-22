<?php

use App\Http\Controllers\Internal\AiNodeConfigInternalController;
use App\Http\Controllers\Internal\AiNodeInternalController;
use App\Http\Controllers\Internal\CameraHealthInternalController;
use App\Http\Controllers\Internal\FaceRecognitionInternalController;
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

    // CAM-009: pgvector search + FACE-013 classification for an embedding
    // the node already computed locally. No access decision; no raw
    // embedding is ever returned.
    Route::post('/face/recognize', [FaceRecognitionInternalController::class, 'recognize']);

    // CAM-013: raw camera telemetry snapshot. cameraUuid is resolved
    // manually in the controller (no SubstituteBindings middleware on this
    // machine-to-machine stack); the service rejects a node reporting for
    // a camera it is not assigned to.
    Route::post('/cameras/{cameraUuid}/health', [CameraHealthInternalController::class, 'record']);

    // CAM-015: camera configuration sync, including decrypted RTSP
    // credentials — the one legitimate consumer of that plaintext. A node
    // may only ever fetch its own configuration.
    Route::get('/ai-nodes/{aiNodeUuid}/config', [AiNodeConfigInternalController::class, 'show']);
});
