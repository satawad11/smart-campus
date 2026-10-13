<?php

namespace App\Services\Face;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\AuditAction;
use App\Enums\FaceProfileStatus;
use App\Enums\FaceSamplePose;
use App\Enums\FaceSampleSource;
use App\Exceptions\Face\FaceEnrollmentException;
use App\Exceptions\Face\FacePipelineUnavailableException;
use App\Exceptions\Face\FaceQualityRejectedException;
use App\Exceptions\Face\IncompatibleModelException;
use App\Exceptions\Face\InvalidImageException;
use App\Exceptions\Face\MultipleFacesDetectedException;
use App\Exceptions\Face\NoFaceDetectedException;
use App\Exceptions\Face\PersonAlreadyEnrolledException;
use App\Models\AIModel;
use App\Models\FaceProfile;
use App\Models\FaceSample;
use App\Models\Person;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use App\Services\License\LicenseService;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Authoritative Face Enrollment Service (FACE-009).
 *
 * Establishes biometric enrollment identity exclusively rooted at Person.
 * Orchestrates image validation, private object staging, AI perception pipeline,
 * atomic DB persistence, compensation on failure, and audit logging.
 */
class FaceEnrollmentService
{
    public function __construct(
        protected FacePipelineClient $pipelineClient,
        protected FaceEmbeddingRepository $embeddingRepository,
        protected AuditService $auditService,
        protected LicenseService $licenseService,
    ) {}

    /**
     * Enroll a new biometric face profile for a Person.
     *
     * @throws PersonAlreadyEnrolledException
     * @throws NoFaceDetectedException
     * @throws MultipleFacesDetectedException
     * @throws FaceQualityRejectedException
     * @throws InvalidImageException
     * @throws FacePipelineUnavailableException
     * @throws FaceEnrollmentException
     */
    public function enroll(
        Person $person,
        UploadedFile|string $imageInput,
        ?User $actor = null,
        FaceSamplePose $pose = FaceSamplePose::FRONT,
        FaceSampleSource $source = FaceSampleSource::UPLOAD,
        ?AuditRequestContext $context = null
    ): FaceProfile {
        // Read raw image bytes
        if ($imageInput instanceof UploadedFile) {
            $imageBytes = (string) $imageInput->get();
            $mimeType = $imageInput->getMimeType() ?? 'image/jpeg';
        } else {
            $imageBytes = $imageInput;
            $mimeType = 'image/jpeg';
        }

        if (empty($imageBytes)) {
            throw new InvalidImageException('Enrollment image payload cannot be empty.');
        }

        // Check if Person already has an active biometric profile before doing work
        $existingProfile = FaceProfile::where('person_id', $person->id)
            ->where('status', FaceProfileStatus::ACTIVE)
            ->exists();

        if ($existingProfile) {
            throw new PersonAlreadyEnrolledException(
                "Person [{$person->uuid}] already has an active biometric face profile."
            );
        }

        // COM-02: a new enrollment is growth the license must cover.
        $this->licenseService->assertCanGrow(LicenseService::RESOURCE_FACE_ENROLLMENT, $actor, $context);

        // 1. Stage image in private object storage (face_samples disk)
        $sampleUuid = (string) Str::uuid();
        $extension = 'jpg';
        if (str_contains(strtolower($mimeType), 'png')) {
            $extension = 'png';
        } elseif (str_contains(strtolower($mimeType), 'webp')) {
            $extension = 'webp';
        }
        $storagePath = "biometrics/faces/samples/{$sampleUuid}.{$extension}";
        $checksum = hash('sha256', $imageBytes);

        try {
            Storage::disk('face_samples')->put($storagePath, $imageBytes);
        } catch (Throwable $e) {
            throw new FaceEnrollmentException(
                "Failed to stage biometric image in private storage: {$e->getMessage()}",
                'STORAGE_WRITE_FAILED',
                500
            );
        }

        // 2. Execute AI perception pipeline with compensation guard
        try {
            $pipelineResult = $this->pipelineClient->processEnrollment(
                imageBytes: $imageBytes,
                mimeType: $mimeType,
                pose: $pose->value,
                source: $source->value
            );
        } catch (Throwable $e) {
            // Remove staged file on perception failure
            $this->compensateStorage($storagePath);
            throw $e;
        }

        // 3. Resolve active AI embedding model
        $aiModel = AIModel::query()
            ->where('model_type', AIModelType::FACE_EMBEDDING)
            ->where('status', AIModelStatus::ACTIVE)
            ->where('code', $pipelineResult->modelCode)
            ->where('version', $pipelineResult->modelVersion)
            ->first();

        if (! $aiModel) {
            // Fallback to active FACE_EMBEDDING model if code/version matched default or registered
            $aiModel = AIModel::query()
                ->where('model_type', AIModelType::FACE_EMBEDDING)
                ->where('status', AIModelStatus::ACTIVE)
                ->first();
        }

        if (! $aiModel) {
            $this->compensateStorage($storagePath);
            throw new IncompatibleModelException(
                'No active AI model of type FACE_EMBEDDING found in registry.'
            );
        }

        // 4. Atomic Database Persistence & Audit
        try {
            return DB::transaction(function () use (
                $person,
                $aiModel,
                $pipelineResult,
                $storagePath,
                $checksum,
                $mimeType,
                $pose,
                $source,
                $actor,
                $context
            ) {
                // Lock person record to prevent race conditions from concurrent enrollment requests
                $lockedPerson = Person::where('id', $person->id)->lockForUpdate()->first();

                if (! $lockedPerson) {
                    throw new FaceEnrollmentException('Target Person not found.', 'PERSON_NOT_FOUND', 404);
                }

                $activeCount = FaceProfile::where('person_id', $lockedPerson->id)
                    ->where('status', FaceProfileStatus::ACTIVE)
                    ->count();

                if ($activeCount > 0) {
                    throw new PersonAlreadyEnrolledException(
                        "Person [{$lockedPerson->uuid}] already has an active biometric face profile."
                    );
                }

                // Create FaceProfile
                $profile = new FaceProfile([
                    'person_id' => $lockedPerson->id,
                    'status' => FaceProfileStatus::ACTIVE,
                    'recognition_model_id' => $aiModel->id,
                    'embedding_dimension' => $pipelineResult->embeddingDimension,
                    'quality_score' => $pipelineResult->qualityScore,
                    'enrolled_by_user_id' => $actor?->id,
                    'enrolled_at' => now(),
                ]);
                $profile->save();

                // Create FaceSample
                $sample = new FaceSample([
                    'face_profile_id' => $profile->id,
                    'image_path' => $storagePath,
                    'pose' => $pose,
                    'source' => $source,
                    'quality_score' => $pipelineResult->qualityScore,
                    'blur_score' => $pipelineResult->blurScore,
                    'brightness_score' => $pipelineResult->brightnessScore,
                    'face_size_score' => $pipelineResult->faceSizeScore,
                    'yaw' => $pipelineResult->yaw,
                    'pitch' => $pipelineResult->pitch,
                    'roll' => $pipelineResult->roll,
                    'accepted' => true,
                    'checksum' => $checksum,
                    'mime_type' => $mimeType,
                    'captured_at' => now(),
                ]);
                $sample->save();

                // Store embedding vector using FACE-008 authoritative repository
                $this->embeddingRepository->store($profile, $pipelineResult->embedding, $aiModel);

                // Record Audit Log with sanitized safe metadata
                $this->auditService->record(
                    action: AuditAction::FACE_ENROLLED,
                    actor: $actor,
                    subjectType: 'Person',
                    subjectUuid: $lockedPerson->uuid,
                    oldValues: null,
                    newValues: [
                        'face_profile_uuid' => $profile->uuid,
                        'sample_uuid' => $sample->uuid,
                        'status' => FaceProfileStatus::ACTIVE->value,
                        'model_code' => $aiModel->code,
                        'model_version' => $aiModel->version,
                        'quality_score' => $pipelineResult->qualityScore,
                    ],
                    metadata: [
                        'person_uuid' => $lockedPerson->uuid,
                        'face_profile_uuid' => $profile->uuid,
                        'sample_uuid' => $sample->uuid,
                        'model_code' => $aiModel->code,
                        'model_version' => $aiModel->version,
                        'quality_score' => $pipelineResult->qualityScore,
                        'pose' => $pose->value,
                        'source' => $source->value,
                    ],
                    context: $context,
                    module: 'face_biometrics'
                );

                return $profile->fresh(['person', 'recognitionModel', 'samples', 'enrolledBy']);
            });
        } catch (Throwable $e) {
            // Clean up staged storage object if DB transaction fails
            $this->compensateStorage($storagePath);
            throw $e;
        }
    }

    /**
     * Remove staged biometric file from private storage.
     */
    protected function compensateStorage(string $storagePath): void
    {
        try {
            Storage::disk('face_samples')->delete($storagePath);
        } catch (Exception) {
            // Avoid suppressing original error on compensation failure
        }
    }
}
