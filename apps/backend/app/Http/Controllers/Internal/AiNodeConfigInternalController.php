<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AiNode;
use App\Services\Camera\AiNodeConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Node-facing camera configuration sync (CAM-015), under /internal/v1 and
 * gated by the ai_node.token middleware. Node identity is the credential
 * resolved by that middleware; the {aiNodeUuid} route segment is only
 * checked against it (assertOwnConfig), never trusted on its own — a
 * credential can never be used to read another node's configuration
 * (including its cameras' decrypted RTSP credentials).
 */
class AiNodeConfigInternalController extends Controller
{
    public function __construct(
        private readonly AiNodeConfigService $service,
    ) {}

    public function show(Request $request, string $aiNodeUuid): JsonResponse
    {
        /** @var AiNode $node */
        $node = $request->attributes->get('resolved_ai_node');

        $this->service->assertOwnConfig($node, $aiNodeUuid);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $this->service->getConfig($node),
            'meta' => null,
        ]);
    }
}
