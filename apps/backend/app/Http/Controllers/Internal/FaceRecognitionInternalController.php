<?php

namespace App\Http\Controllers\Internal;

use App\Exceptions\Face\FaceEmbeddingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\RecognizeEmbeddingRequest;
use App\Services\Face\InternalFaceRecognitionService;
use Illuminate\Http\JsonResponse;

/**
 * Node-facing candidate search + classification (CAM-009), under
 * /internal/v1 and gated by ai_node.token (never a human Laravel session).
 */
class FaceRecognitionInternalController extends Controller
{
    public function __construct(
        private readonly InternalFaceRecognitionService $service,
    ) {}

    public function recognize(RecognizeEmbeddingRequest $request): JsonResponse
    {
        try {
            $result = $this->service->recognize(
                embedding: $request->validated('embedding'),
                modelCode: $request->validated('model_code'),
                modelVersion: $request->validated('model_version'),
                topK: $request->validated('top_k'),
            );
        } catch (FaceEmbeddingException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => ['code' => 'EMBEDDING_SEARCH_FAILED', 'fields' => new \stdClass],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $result->toArray(),
            'meta' => null,
        ]);
    }
}
