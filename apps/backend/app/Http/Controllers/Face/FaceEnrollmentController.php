<?php

namespace App\Http\Controllers\Face;

use App\Enums\FaceSamplePose;
use App\Enums\FaceSampleSource;
use App\Exceptions\Face\FaceEnrollmentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Face\EnrollFaceRequest;
use App\Http\Resources\Face\FaceEnrollmentResource;
use App\Models\Person;
use App\Services\Audit\AuditRequestContext;
use App\Services\Face\FaceEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

/**
 * Face Enrollment Controller (FACE-009).
 */
class FaceEnrollmentController extends Controller
{
    public function __construct(
        protected FaceEnrollmentService $enrollmentService
    ) {}

    /**
     * Handle face enrollment for a target Person.
     */
    public function enroll(EnrollFaceRequest $request, ?Person $person = null): JsonResponse
    {
        // Support both /persons/{person}/face/enroll and /faces/enroll with person_uuid
        if (! $person || ! $person->exists) {
            $personUuid = $request->input('person_uuid');
            if (! $personUuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Target Person UUID is required.',
                    'error' => [
                        'code' => 'PERSON_REQUIRED',
                        'details' => ['person_uuid' => 'Target Person UUID is required.'],
                    ],
                ], 422);
            }

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
        }

        /** @var UploadedFile $imageFile */
        $imageFile = $request->file('image');

        $pose = FaceSamplePose::tryFrom((string) $request->input('pose', 'FRONT')) ?? FaceSamplePose::FRONT;
        $source = FaceSampleSource::tryFrom((string) $request->input('source', 'UPLOAD')) ?? FaceSampleSource::UPLOAD;

        $context = new AuditRequestContext(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->header('X-Request-Id')
        );

        try {
            $profile = $this->enrollmentService->enroll(
                person: $person,
                imageInput: $imageFile,
                actor: $request->user(),
                pose: $pose,
                source: $source,
                context: $context
            );

            return response()->json([
                'success' => true,
                'message' => 'Face biometric profile enrolled successfully.',
                'data' => (new FaceEnrollmentResource($profile))->resolve($request),
                'meta' => null,
            ], 201);
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
