<?php

namespace App\Http\Controllers\Camera;

use App\Enums\DeviceStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\EnforcesScope;
use App\Http\Controllers\Controller;
use App\Models\AiNode;
use App\Services\Audit\AuditRequestContext;
use App\Services\Camera\AiNodeEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin side of COM-03e: issue a one-time enrollment code for a node. Human
 * session + ai_node.manage only. The code is returned once.
 */
class AiNodeEnrollmentCodeController extends Controller
{
    use EnforcesScope;

    public function __construct(
        private readonly AiNodeEnrollmentService $service,
    ) {}

    public function store(Request $request, AiNode $aiNode): JsonResponse
    {
        $this->assertInScope($request, 'ai_node.manage', $aiNode->site_id);
        if ($aiNode->status !== DeviceStatus::ACTIVE) {
            throw ApiException::unprocessable('AI_NODE_NOT_ACTIVE', 'Activate the AI node before issuing an enrollment code.');
        }

        [$row, $code] = $this->service->issueCode($aiNode, $request->user(), AuditRequestContext::fromRequest($request));

        return response()->json([
            'success' => true,
            'message' => 'Enrollment code issued. It is shown once and can be used once.',
            'data' => [
                'uuid' => $row->uuid,
                'code' => $code,
                'expires_at' => $row->expires_at->toIso8601String(),
            ],
            'meta' => null,
        ], 201);
    }
}
