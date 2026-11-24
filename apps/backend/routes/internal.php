<?php

use App\Http\Controllers\Internal\AiNodeCommandInternalController;
use App\Http\Controllers\Internal\AiNodeConfigInternalController;
use App\Http\Controllers\Internal\AiNodeEnrollmentInternalController;
use App\Http\Controllers\Internal\AiNodeInternalController;
use App\Http\Controllers\Internal\CameraHealthInternalController;
use App\Http\Controllers\Internal\FaceRecognitionInternalController;
use App\Http\Controllers\Internal\PlateEventInternalController;
use App\Http\Controllers\Internal\RecognitionEventInternalController;
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
// COM-03e: a node without a credential exchanges a one-time enrollment code
// (issued by an administrator) for its UUID and token. The code is the
// secret, so this one route runs without ai_node.token and is throttled.
Route::post('/ai-nodes/enroll', [AiNodeEnrollmentInternalController::class, 'enroll'])
    ->middleware('throttle:ai-node-enroll');

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

    // EVT-003: recognition-event ingestion. Perception only — no access
    // decision is ever accepted here or produced by this endpoint.
    Route::post('/events/recognition', [RecognitionEventInternalController::class, 'store']);

    // VEH-010 (ACR-VEH-01): plate reads. The node sends raw text and scores; the backend
    // normalises, looks up the vehicle and picks the event type. Never a decision.
    Route::post('/events/plate', [PlateEventInternalController::class, 'store']);

    // COM-03: allowlisted remote operations. The node pulls its own pending
    // commands (marking them delivered) and reports each result.
    Route::post('/ai-nodes/commands/claim', [AiNodeCommandInternalController::class, 'claim']);
    Route::post('/ai-nodes/commands/{commandUuid}/result', [AiNodeCommandInternalController::class, 'result']);
});
