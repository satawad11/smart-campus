<?php

namespace App\Http\Controllers\Face;

use App\Enums\DuplicateReviewStatus;
use App\Exceptions\Face\FaceEmbeddingException;
use App\Exceptions\Face\FaceEnrollmentException;
use App\Exceptions\Face\IncompatibleModelException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Face\SubmitDuplicateReviewDecisionRequest;
use App\Http\Resources\Face\FaceDuplicateReviewResource;
use App\Http\Resources\Face\FaceDuplicateReviewResultResource;
use App\Models\FaceDuplicateReview;
use App\Models\FaceProfile;
use App\Models\Person;
use App\Services\Audit\AuditRequestContext;
use App\Services\Face\FaceDuplicateReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller for Duplicate Face Review (FACE-011).
 */
class FaceDuplicateReviewController extends Controller
{
    public function __construct(
        protected FaceDuplicateReviewService $duplicateReviewService
    ) {}

    /**
     * Find duplicate candidates for a specific FaceProfile.
     */
    public function checkByProfile(Request $request, FaceProfile $faceProfile): JsonResponse
    {
        $limit = $request->query('limit') ? (int) $request->query('limit') : null;

        try {
            $result = $this->duplicateReviewService->findDuplicates($faceProfile, $limit);

            return response()->json([
                'success' => true,
                'message' => 'Duplicate face review query completed.',
                'data' => (new FaceDuplicateReviewResultResource($result))->resolve($request),
                'meta' => null,
            ], 200);
        } catch (IncompatibleModelException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => [
                    'code' => 'INCOMPATIBLE_MODEL',
                    'details' => ['model' => $e->getMessage()],
                ],
            ], 422);
        } catch (FaceEmbeddingException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => [
                    'code' => 'EMBEDDING_ERROR',
                    'details' => ['embedding' => $e->getMessage()],
                ],
            ], 422);
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

    /**
     * Find duplicate candidates for a Person's active FaceProfile.
     */
    public function checkByPerson(Request $request, Person $person): JsonResponse
    {
        $profile = FaceProfile::where('person_id', $person->id)->first();

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => "No face profile found for Person [{$person->uuid}].",
                'error' => [
                    'code' => 'FACE_PROFILE_NOT_FOUND',
                    'details' => ['person_uuid' => "No face profile found for Person [{$person->uuid}]."],
                ],
            ], 404);
        }

        return $this->checkByProfile($request, $profile);
    }

    /**
     * Submit a human duplicate-review decision.
     */
    public function submitDecision(
        SubmitDuplicateReviewDecisionRequest $request,
        FaceDuplicateReview $duplicateReview
    ): JsonResponse {
        $decision = DuplicateReviewStatus::from((string) $request->input('decision'));
        $note = $request->input('review_note');

        $context = new AuditRequestContext(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->header('X-Request-Id')
        );

        try {
            $updatedReview = $this->duplicateReviewService->recordDecision(
                review: $duplicateReview,
                decision: $decision,
                note: $note,
                actor: $request->user(),
                context: $context
            );

            return response()->json([
                'success' => true,
                'message' => 'Duplicate review decision recorded successfully.',
                'data' => (new FaceDuplicateReviewResource($updatedReview))->resolve($request),
                'meta' => null,
            ], 200);
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
