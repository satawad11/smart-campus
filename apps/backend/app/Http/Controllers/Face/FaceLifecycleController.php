<?php

namespace App\Http\Controllers\Face;

use App\Enums\FaceProfileStatus;
use App\Enums\FaceSamplePose;
use App\Enums\FaceSampleSource;
use App\Exceptions\Face\FaceEnrollmentException;
use App\Exceptions\Face\FaceLifecycleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Face\ReenrollFaceRequest;
use App\Http\Requests\Face\RevokeFaceProfileRequest;
use App\Http\Resources\Face\FaceProfileResource;
use App\Models\FaceProfile;
use App\Models\Person;
use App\Services\Audit\AuditRequestContext;
use App\Services\Face\FaceLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FaceLifecycleController extends Controller
{
    public function __construct(
        protected FaceLifecycleService $lifecycleService
    ) {}

    /**
     * List all face profiles (FACE-017).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = FaceProfile::with(['person', 'recognitionModel', 'samples'])
            ->latest('id');

        if ($request->filled('status')) {
            $status = strtoupper((string) $request->input('status'));
            if (in_array($status, array_column(FaceProfileStatus::cases(), 'value'), true)) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('uuid', $search)
                    ->orWhereHas('person', function ($pq) use ($search) {
                        $pq->where('uuid', $search)
                            ->orWhere('full_name', 'ilike', "%{$search}%")
                            ->orWhere('id_number', 'ilike', "%{$search}%");
                    });
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

        return FaceProfileResource::collection($query->paginate($perPage));
    }

    /**
     * Show single face profile details (FACE-017).
     */
    public function show(FaceProfile $faceProfile): JsonResponse
    {
        $faceProfile->load(['person', 'recognitionModel', 'samples']);

        return response()->json([
            'success' => true,
            'message' => 'Face profile retrieved successfully.',
            'data' => (new FaceProfileResource($faceProfile))->resolve(request()),
            'meta' => null,
        ], 200);
    }

    /**
     * Get biometric profile for a specific person.
     */
    public function personFaces(Person $person): JsonResponse
    {
        $profile = FaceProfile::where('person_id', $person->id)
            ->with(['person', 'recognitionModel', 'samples'])
            ->first();

        if (! $profile) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No biometric face profile found for this person.',
                'meta' => null,
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Face profile retrieved successfully.',
            'data' => (new FaceProfileResource($profile))->resolve(request()),
            'meta' => null,
        ], 200);
    }

    /**
     * Revoke biometric face profile by profile UUID.
     */
    public function revoke(RevokeFaceProfileRequest $request, FaceProfile $faceProfile): JsonResponse
    {
        $context = new AuditRequestContext(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->header('X-Request-Id')
        );

        try {
            $updatedProfile = $this->lifecycleService->revoke(
                profile: $faceProfile,
                reason: $request->validated('reason'),
                actor: $request->user(),
                context: $context
            );

            return response()->json([
                'success' => true,
                'message' => 'Biometric face profile revoked successfully.',
                'data' => (new FaceProfileResource($updatedProfile))->resolve($request),
                'meta' => null,
            ], 200);
        } catch (FaceLifecycleException|FaceEnrollmentException $e) {
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
     * Revoke biometric face profile by target Person UUID.
     */
    public function revokeByPerson(RevokeFaceProfileRequest $request, Person $person): JsonResponse
    {
        $context = new AuditRequestContext(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->header('X-Request-Id')
        );

        try {
            $updatedProfile = $this->lifecycleService->revokeByPerson(
                person: $person,
                reason: $request->validated('reason'),
                actor: $request->user(),
                context: $context
            );

            return response()->json([
                'success' => true,
                'message' => 'Biometric face profile revoked successfully.',
                'data' => (new FaceProfileResource($updatedProfile))->resolve($request),
                'meta' => null,
            ], 200);
        } catch (FaceLifecycleException|FaceEnrollmentException $e) {
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
     * Re-enroll biometric face profile by profile UUID.
     */
    public function reenroll(ReenrollFaceRequest $request, FaceProfile $faceProfile): JsonResponse
    {
        $context = new AuditRequestContext(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->header('X-Request-Id')
        );

        $pose = $request->filled('pose')
            ? FaceSamplePose::from($request->validated('pose'))
            : FaceSamplePose::FRONT;

        $source = $request->filled('source')
            ? FaceSampleSource::from($request->validated('source'))
            : FaceSampleSource::UPLOAD;

        try {
            $updatedProfile = $this->lifecycleService->reenrollByProfile(
                profile: $faceProfile,
                imageInput: $request->file('image'),
                reason: $request->validated('reason'),
                actor: $request->user(),
                pose: $pose,
                source: $source,
                context: $context
            );

            return response()->json([
                'success' => true,
                'message' => 'Biometric face profile re-enrolled successfully.',
                'data' => (new FaceProfileResource($updatedProfile))->resolve($request),
                'meta' => null,
            ], 200);
        } catch (FaceLifecycleException|FaceEnrollmentException $e) {
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
     * Re-enroll biometric face profile by target Person UUID.
     */
    public function reenrollByPerson(ReenrollFaceRequest $request, Person $person): JsonResponse
    {
        $context = new AuditRequestContext(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->header('X-Request-Id')
        );

        $pose = $request->filled('pose')
            ? FaceSamplePose::from($request->validated('pose'))
            : FaceSamplePose::FRONT;

        $source = $request->filled('source')
            ? FaceSampleSource::from($request->validated('source'))
            : FaceSampleSource::UPLOAD;

        try {
            $updatedProfile = $this->lifecycleService->reenroll(
                person: $person,
                imageInput: $request->file('image'),
                reason: $request->validated('reason'),
                actor: $request->user(),
                pose: $pose,
                source: $source,
                context: $context
            );

            return response()->json([
                'success' => true,
                'message' => 'Biometric face profile re-enrolled successfully.',
                'data' => (new FaceProfileResource($updatedProfile))->resolve($request),
                'meta' => null,
            ], 200);
        } catch (FaceLifecycleException|FaceEnrollmentException $e) {
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
