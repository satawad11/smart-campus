<?php

namespace App\Http\Controllers\Face;

use App\Exceptions\Face\FaceEmbeddingException;
use App\Exceptions\Face\FaceEnrollmentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Face\FaceScanImageRequest;
use App\Services\Audit\AuditRequestContext;
use App\Services\Face\FaceScanService;
use Illuminate\Http\JsonResponse;
use stdClass;

/**
 * Webcam face scanning endpoints (FACE-022, docs/02-api-contract.md §8).
 *
 * POST /api/v1/faces/check-quality  live capture indicators (face.enroll or face.test)
 * POST /api/v1/faces/recognize      MATCH/REVIEW/UNKNOWN for one frame (face.test)
 */
class FaceScanController extends Controller
{
    public function __construct(
        protected FaceScanService $scanService
    ) {}

    public function checkQuality(FaceScanImageRequest $request): JsonResponse
    {
        try {
            $data = $this->scanService->checkQuality($request->file('image'));
        } catch (FaceEnrollmentException $e) {
            return $this->error($e->getMessage(), $e->errorCode, $e->statusCode, $e->details);
        }

        return response()->json(['success' => true, 'message' => 'Success', 'data' => $data, 'meta' => null]);
    }

    public function recognize(FaceScanImageRequest $request): JsonResponse
    {
        $context = new AuditRequestContext(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->header('X-Request-Id')
        );

        try {
            $data = $this->scanService->recognize($request->file('image'), $request->user(), $context);
        } catch (FaceEnrollmentException $e) {
            return $this->error($e->getMessage(), $e->errorCode, $e->statusCode, $e->details);
        } catch (FaceEmbeddingException $e) {
            // No active embedding model matches the AI service's model/version.
            return $this->error($e->getMessage(), 'INCOMPATIBLE_MODEL', 422);
        }

        return response()->json(['success' => true, 'message' => 'Success', 'data' => $data, 'meta' => null]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function error(string $message, string $code, int $status, array $fields = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => ['code' => $code, 'fields' => $fields === [] ? new stdClass : $fields],
        ], $status);
    }
}
