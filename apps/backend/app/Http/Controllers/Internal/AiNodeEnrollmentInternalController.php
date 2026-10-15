<?php

namespace App\Http\Controllers\Internal;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Camera\AiNodeEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Node side of COM-03e: exchange a one-time enrollment code for the node
 * UUID and a machine credential. The only /internal/v1 endpoint without a
 * node bearer token (the node has none yet): the code is the secret, and the
 * route is throttled per IP. Every failure looks the same.
 */
class AiNodeEnrollmentInternalController extends Controller
{
    public function __construct(
        private readonly AiNodeEnrollmentService $service,
    ) {}

    public function enroll(Request $request): JsonResponse
    {
        $code = $request->input('code');
        $result = is_string($code) && strlen($code) <= 64
            ? $this->service->exchange($code, $request->ip())
            : null;

        if ($result === null) {
            throw ApiException::unprocessable('INVALID_ENROLLMENT_CODE', 'Invalid, used or expired enrollment code.');
        }

        [$node, $token] = $result;

        return response()->json([
            'success' => true,
            'message' => 'Enrolled. Store the token now; it is not shown again.',
            'data' => [
                'ai_node_uuid' => $node->uuid,
                'node_code' => $node->node_code,
                'token' => $token,
            ],
            'meta' => null,
        ], 201);
    }
}
