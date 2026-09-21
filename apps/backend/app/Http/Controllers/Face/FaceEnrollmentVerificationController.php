<?php

namespace App\Http\Controllers\Face;

use App\Exceptions\Face\FaceVerificationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Face\VerifyFaceEnrollmentRequest;
use App\Http\Resources\Face\FaceVerificationResource;
use App\Models\FaceProfile;
use App\Models\Person;
use App\Services\Audit\AuditRequestContext;
use App\Services\Face\FaceEnrollmentVerificationService;
use Illuminate\Http\JsonResponse;

/**
 * Controller for Face Enrollment Verification (FACE-010).
 */
class FaceEnrollmentVerificationController extends Controller
{
    public function __construct(
        protected FaceEnrollmentVerificationService $verificationService
    ) {}

    /**
     * Verify face enrollment for a target Person.
     */
    public function verifyByPerson(VerifyFaceEnrollmentRequest $request, Person $person): JsonResponse
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

        return $this->executeVerification($request, $profile);
    }

    /**
     * Verify face enrollment for a target FaceProfile.
     */
    public function verifyByProfile(VerifyFaceEnrollmentRequest $request, FaceProfile $faceProfile): JsonResponse
    {
        return $this->executeVerification($request, $faceProfile);
    }

    /**
     * Verify face enrollment using public UUID in request payload.
     */
    public function verify(VerifyFaceEnrollmentRequest $request): JsonResponse
    {
        $profileUuid = $request->input('face_profile_uuid');
        $personUuid = $request->input('person_uuid');

        if (! $profileUuid && ! $personUuid) {
            return response()->json([
                'success' => false,
                'message' => 'Either face_profile_uuid or person_uuid is required.',
                'error' => [
                    'code' => 'TARGET_REQUIRED',
                    'details' => ['target' => 'Either face_profile_uuid or person_uuid is required.'],
                ],
            ], 422);
        }

        if ($profileUuid) {
            $profile = FaceProfile::where('uuid', $profileUuid)->first();
            if (! $profile) {
                return response()->json([
                    'success' => false,
                    'message' => "FaceProfile [{$profileUuid}] not found.",
                    'error' => [
                        'code' => 'FACE_PROFILE_NOT_FOUND',
                        'details' => ['face_profile_uuid' => "FaceProfile [{$profileUuid}] not found."],
                    ],
                ], 404);
            }
        } else {
            $person = Person::where('uuid', $personUuid)->first();
            if (! $person) {
                return response()->json([
                    'success' => false,
                    'message' => "Person [{$personUuid}] not found.",
                    'error' => [
                        'code' => 'PERSON_NOT_FOUND',
                        'details' => ['person_uuid' => "Person [{$personUuid}] not found."],
                    ],
                ], 404);
            }

            $profile = FaceProfile::where('person_id', $person->id)->first();
            if (! $profile) {
                return response()->json([
                    'success' => false,
                    'message' => "No face profile found for Person [{$personUuid}].",
                    'error' => [
                        'code' => 'FACE_PROFILE_NOT_FOUND',
                        'details' => ['person_uuid' => "No face profile found for Person [{$personUuid}]."],
                    ],
                ], 404);
            }
        }

        return $this->executeVerification($request, $profile);
    }

    /**
     * Common execution handler for verification.
     */
    protected function executeVerification(VerifyFaceEnrollmentRequest $request, FaceProfile $profile): JsonResponse
    {
        $context = new AuditRequestContext(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->header('X-Request-Id')
        );

        try {
            $result = $this->verificationService->verify(
                faceProfile: $profile,
                actor: $request->user(),
                context: $context
            );

            return response()->json([
                'success' => true,
                'message' => 'Face enrollment verified successfully.',
                'data' => (new FaceVerificationResource($result))->resolve($request),
                'meta' => null,
            ], 200);
        } catch (FaceVerificationException $e) {
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
