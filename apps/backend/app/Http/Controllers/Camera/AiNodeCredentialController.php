<?php

namespace App\Http\Controllers\Camera;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiNodeCredentialResource;
use App\Models\AiNode;
use App\Models\AiNodeCredential;
use App\Services\Audit\AuditRequestContext;
use App\Services\Camera\AiNodeCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin credential lifecycle for an AI node (CAM-004). Human session +
 * ai_node.manage only — never reachable by a node itself. The plaintext
 * token is returned exactly once, in the issue response, and never again
 * (not even to this same admin) — matching standard API-token UX.
 */
class AiNodeCredentialController extends Controller
{
    public function __construct(
        private readonly AiNodeCredentialService $service,
    ) {}

    public function index(AiNode $aiNode): JsonResponse
    {
        $credentials = $aiNode->credentials()->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => AiNodeCredentialResource::collection($credentials),
            'meta' => null,
        ]);
    }

    public function store(Request $request, AiNode $aiNode): JsonResponse
    {
        [$credential, $plaintext] = $this->service->issueCredential(
            $aiNode,
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Credential issued. This token is shown once and cannot be retrieved again.',
            'data' => [
                ...(new AiNodeCredentialResource($credential))->toArray($request),
                'token' => $plaintext,
            ],
            'meta' => null,
        ], 201);
    }

    public function revoke(Request $request, AiNode $aiNode, AiNodeCredential $credential): JsonResponse
    {
        if ($credential->ai_node_id !== $aiNode->id) {
            throw ApiException::unprocessable('AI_NODE_CREDENTIAL_MISMATCH', 'This credential does not belong to the specified node.');
        }

        $revoked = $this->service->revokeCredential($credential, $request->user(), AuditRequestContext::fromRequest($request));

        return response()->json([
            'success' => true,
            'message' => 'Credential revoked',
            'data' => new AiNodeCredentialResource($revoked),
            'meta' => null,
        ]);
    }
}
