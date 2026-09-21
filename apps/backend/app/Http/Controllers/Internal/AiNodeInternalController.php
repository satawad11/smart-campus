<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiNode\Internal\AiNodeHeartbeatRequest;
use App\Http\Requests\AiNode\Internal\CheckInAiNodeRequest;
use App\Http\Resources\AiNodeResource;
use App\Services\Audit\AuditRequestContext;
use App\Services\Camera\AiNodeService;
use Illuminate\Http\JsonResponse;

/**
 * Node-facing registration/heartbeat surface (CAM-003), under
 * /internal/v1 and gated by the internal.token middleware (never a human
 * Laravel session — machine credentials and human sessions stay separate).
 */
class AiNodeInternalController extends Controller
{
    public function __construct(
        private readonly AiNodeService $service,
    ) {}

    public function register(CheckInAiNodeRequest $request): JsonResponse
    {
        $node = $this->service->checkIn(
            $request->validated('node_code'),
            $request->validated(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'AI node check-in recorded',
            'data' => new AiNodeResource($node->load('site')),
            'meta' => null,
        ]);
    }

    public function heartbeat(AiNodeHeartbeatRequest $request): JsonResponse
    {
        $node = $this->service->heartbeat($request->validated('node_code'), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Heartbeat recorded',
            'data' => new AiNodeResource($node),
            'meta' => null,
        ]);
    }
}
