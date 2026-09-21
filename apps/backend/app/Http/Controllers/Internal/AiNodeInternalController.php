<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiNode\Internal\AiNodeHeartbeatRequest;
use App\Http\Requests\AiNode\Internal\CheckInAiNodeRequest;
use App\Http\Resources\AiNodeResource;
use App\Models\AiNode;
use App\Services\Audit\AuditRequestContext;
use App\Services\Camera\AiNodeService;
use Illuminate\Http\JsonResponse;

/**
 * Node-facing registration/heartbeat surface (CAM-003/CAM-004), under
 * /internal/v1 and gated by the ai_node.token middleware (never a human
 * Laravel session — machine credentials and human sessions stay separate).
 * Identity is the node resolved by that middleware from its own credential,
 * never a client-supplied node_code.
 */
class AiNodeInternalController extends Controller
{
    public function __construct(
        private readonly AiNodeService $service,
    ) {}

    public function register(CheckInAiNodeRequest $request): JsonResponse
    {
        $node = $this->service->checkIn(
            $this->resolvedNode($request),
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
        $node = $this->service->heartbeat($this->resolvedNode($request), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Heartbeat recorded',
            'data' => new AiNodeResource($node),
            'meta' => null,
        ]);
    }

    private function resolvedNode(CheckInAiNodeRequest|AiNodeHeartbeatRequest $request): AiNode
    {
        return $request->attributes->get('resolved_ai_node');
    }
}
