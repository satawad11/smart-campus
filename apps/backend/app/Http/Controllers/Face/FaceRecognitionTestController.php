<?php

namespace App\Http\Controllers\Face;

use App\Exceptions\Face\FaceEnrollmentException;
use App\Exceptions\Face\FacePipelineUnavailableException;
use App\Exceptions\Face\FaceQualityRejectedException;
use App\Exceptions\Face\IncompatibleModelException;
use App\Exceptions\Face\InvalidImageException;
use App\Exceptions\Face\MultipleFacesDetectedException;
use App\Exceptions\Face\NoFaceDetectedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Face\RecognitionTestRequest;
use App\Http\Resources\Face\FaceRecognitionTestResource;
use App\Services\Audit\AuditRequestContext;
use App\Services\Face\FaceRecognitionTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

/**
 * Controller for Restricted Face Recognition Diagnostic Testing (FACE-012).
 */
class FaceRecognitionTestController extends Controller
{
    public function __construct(
        protected FaceRecognitionTestService $recognitionTestService
    ) {}

    /**
     * Execute a restricted face recognition test for authorized operators.
     */
    public function test(RecognitionTestRequest $request): JsonResponse
    {
        /** @var UploadedFile $imageFile */
        $imageFile = $request->file('image');
        $topK = $request->input('top_k') !== null ? (int) $request->input('top_k') : null;

        $context = new AuditRequestContext(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->header('X-Request-Id')
        );

        try {
            $result = $this->recognitionTestService->executeTest(
                imageInput: $imageFile,
                topK: $topK,
                actor: $request->user(),
                context: $context
            );

            return response()->json([
                'success' => true,
                'message' => 'Face recognition diagnostic test completed successfully.',
                'data' => (new FaceRecognitionTestResource($result))->resolve($request),
                'meta' => null,
            ], 200);
        } catch (NoFaceDetectedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => [
                    'code' => $e->errorCode,
                    'details' => ['image' => $e->getMessage()],
                ],
            ], $e->statusCode);
        } catch (MultipleFacesDetectedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => [
                    'code' => $e->errorCode,
                    'details' => $e->details,
                ],
            ], $e->statusCode);
        } catch (FaceQualityRejectedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => [
                    'code' => $e->errorCode,
                    'details' => $e->details,
                ],
            ], $e->statusCode);
        } catch (InvalidImageException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => [
                    'code' => $e->errorCode,
                    'details' => ['image' => $e->getMessage()],
                ],
            ], $e->statusCode);
        } catch (IncompatibleModelException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => [
                    'code' => 'INCOMPATIBLE_MODEL',
                    'details' => ['model' => $e->getMessage()],
                ],
            ], 422);
        } catch (FacePipelineUnavailableException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => [
                    'code' => $e->errorCode,
                    'details' => ['service' => 'AI perception service is currently unavailable.'],
                ],
            ], $e->statusCode);
        } catch (FaceEnrollmentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => [
                    'code' => $e->errorCode,
                    'details' => $e->details,
                ],
            ], $e->statusCode);
        }
    }
}
