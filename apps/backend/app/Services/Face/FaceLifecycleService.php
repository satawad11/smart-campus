<?php

namespace App\Services\Face;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\AuditAction;
use App\Enums\FaceProfileStatus;
use App\Enums\FaceSamplePose;
use App\Enums\FaceSampleSource;
use App\Exceptions\Face\FaceEnrollmentException;
use App\Exceptions\Face\FaceLifecycleException;
use App\Exceptions\Face\IncompatibleModelException;
use App\Exceptions\Face\InvalidImageException;
use App\Models\AIModel;
use App\Models\FaceProfile;
use App\Models\FaceSample;
use App\Models\Person;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Biometric Profile Lifecycle Service (FACE-014, database blueprint §5, Module 03).
 *
 * Manages revocation and re-enrollment transitions while preserving
 * historical audit logs, sample records, and identity root at Person.
 */
class FaceLifecycleService
{
    public function __construct(
        protected FacePipelineClient $pipelineClient,
        protected FaceEmbeddingRepository $embeddingRepository,
        protected AuditService $auditService
    ) {}

    /**
     * Revoke an active or pending biometric face profile.
     *
     * @throws FaceLifecycleException
     */
    public function revoke(
        FaceProfile $profile,
        string $reason,
        ?User $actor = null,
        ?AuditRequestContext $context = null
    ): FaceProfile {
        $reason = trim($reason);
        if ($reason === '') {
            throw new FaceLifecycleException('Revocation reason is required.', 'REVOCATION_REASON_REQUIRED', 422);
        }

        return DB::transaction(function () use ($profile, $reason, $actor, $context) {
            /** @var FaceProfile|null $lockedProfile */
            $lockedProfile = FaceProfile::where('id', $profile->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedProfile) {
                throw new FaceLifecycleException('Face profile not found.', 'FACE_PROFILE_NOT_FOUND', 404);
            }

            if ($lockedProfile->status === FaceProfileStatus::REVOKED) {
                throw new FaceLifecycleException(
                    "Face profile [{$lockedProfile->uuid}] is already revoked.",
                    'ALREADY_REVOKED',
                    422
                );
            }

            $oldStatus = $lockedProfile->status->value;
            $lockedProfile->status = FaceProfileStatus::REVOKED;
            $lockedProfile->revoked_at = now();
            $lockedProfile->revocation_reason = $reason;
            $lockedProfile->save();

            $person = $lockedProfile->person;

            // Record security audit log
            $this->auditService->record(
                action: AuditAction::FACE_REVOKED,
                actor: $actor,
                subjectType: 'Person',
                subjectUuid: $person?->uuid ?? $lockedProfile->uuid,
                oldValues: [
                    'face_profile_uuid' => $lockedProfile->uuid,
                    'status' => $oldStatus,
                    'revoked_at' => null,
                    'revocation_reason' => null,
                ],
                newValues: [
                    'face_profile_uuid' => $lockedProfile->uuid,
                    'status' => FaceProfileStatus::REVOKED->value,
                    'revoked_at' => $lockedProfile->revoked_at?->toIso8601String(),
                    'revocation_reason' => $reason,
                ],
                metadata: [
                    'person_uuid' => $person?->uuid,
                    'face_profile_uuid' => $lockedProfile->uuid,
                    'reason' => $reason,
                ],
                context: $context,
                module: 'face_biometrics'
            );

            return $lockedProfile->fresh(['person', 'recognitionModel', 'samples', 'enrolledBy']);
        });
    }

    /**
     * Revoke biometric face profile by target Person.
     *
     * @throws FaceLifecycleException
     */
    public function revokeByPerson(
        Person $person,
        string $reason,
        ?User $actor = null,
        ?AuditRequestContext $context = null
    ): FaceProfile {
        $profile = FaceProfile::where('person_id', $person->id)->first();

        if (! $profile) {
            throw new FaceLifecycleException(
                "No biometric face profile exists for Person [{$person->uuid}].",
                'FACE_PROFILE_NOT_FOUND',
                404
            );
        }

        return $this->revoke($profile, $reason, $actor, $context);
    }

    /**
     * Re-enroll a person's biometric face profile with a new biometric capture.
     * Preserves historical samples while updating the active embedding and model provenance.
     *
     * @throws FaceLifecycleException
     * @throws InvalidImageException
     * @throws IncompatibleModelException
     * @throws FaceEnrollmentException
     */
    public function reenroll(
        Person $person,
        UploadedFile|string $imageInput,
        string $reason,
        ?User $actor = null,
        FaceSamplePose $pose = FaceSamplePose::FRONT,
        FaceSampleSource $source = FaceSampleSource::UPLOAD,
        ?AuditRequestContext $context = null
    ): FaceProfile {
        $reason = trim($reason);
        if ($reason === '') {
            throw new FaceLifecycleException('Re-enrollment reason is required.', 'REENROLL_REASON_REQUIRED', 422);
        }

        if ($imageInput instanceof UploadedFile) {
            $imageBytes = (string) $imageInput->get();
            $mimeType = $imageInput->getMimeType() ?? 'image/jpeg';
        } else {
            $imageBytes = $imageInput;
            $mimeType = 'image/jpeg';
        }

        if (empty($imageBytes)) {
            throw new InvalidImageException('Biometric capture payload cannot be empty.');
        }

        // 1. Stage new sample in private object storage
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

        // 2. Execute AI perception pipeline
        try {
            $pipelineResult = $this->pipelineClient->processEnrollment(
                imageBytes: $imageBytes,
                mimeType: $mimeType,
                pose: $pose->value,
                source: $source->value
            );
        } catch (Throwable $e) {
            $this->compensateStorage($storagePath);
            throw $e;
        }

        // 3. Resolve active embedding model
        $aiModel = AIModel::query()
            ->where('model_type', AIModelType::FACE_EMBEDDING)
            ->where('status', AIModelStatus::ACTIVE)
            ->where('code', $pipelineResult->modelCode)
            ->where('version', $pipelineResult->modelVersion)
            ->first();

        if (! $aiModel) {
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

        // 4. Atomic Database Update & Audit
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
                $reason,
                $actor,
                $context
            ) {
                /** @var Person|null $lockedPerson */
                $lockedPerson = Person::where('id', $person->id)->lockForUpdate()->first();
                if (! $lockedPerson) {
                    throw new FaceLifecycleException('Target Person not found.', 'PERSON_NOT_FOUND', 404);
                }

                /** @var FaceProfile|null $profile */
                $profile = FaceProfile::where('person_id', $lockedPerson->id)->lockForUpdate()->first();

                $isNew = false;
                $oldStatus = null;
                $oldModel = null;

                if (! $profile) {
                    $isNew = true;
                    $profile = new FaceProfile([
                        'person_id' => $lockedPerson->id,
                    ]);
                } else {
                    $oldStatus = $profile->status->value;
                    $oldModel = $profile->recognitionModel ? [
                        'code' => $profile->recognitionModel->code,
                        'version' => $profile->recognitionModel->version,
                    ] : null;
                }

                $profile->status = FaceProfileStatus::ACTIVE;
                $profile->recognition_model_id = $aiModel->id;
                $profile->embedding_dimension = $pipelineResult->embeddingDimension;
                $profile->quality_score = $pipelineResult->qualityScore;
                $profile->enrolled_by_user_id = $actor?->id;
                $profile->enrolled_at = now();
                $profile->revoked_at = null;
                $profile->revocation_reason = null;
                $profile->save();

                // Save new historical sample
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

                // Store new embedding vector
                $this->embeddingRepository->store($profile, $pipelineResult->embedding, $aiModel);

                // Audit log
                $this->auditService->record(
                    action: AuditAction::FACE_REENROLLED,
                    actor: $actor,
                    subjectType: 'Person',
                    subjectUuid: $lockedPerson->uuid,
                    oldValues: [
                        'face_profile_uuid' => $profile->uuid,
                        'status' => $oldStatus,
                        'model' => $oldModel,
                    ],
                    newValues: [
                        'face_profile_uuid' => $profile->uuid,
                        'sample_uuid' => $sample->uuid,
                        'status' => FaceProfileStatus::ACTIVE->value,
                        'model_code' => $aiModel->code,
                        'model_version' => $aiModel->version,
                        'quality_score' => $pipelineResult->qualityScore,
                        'reason' => $reason,
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
                        'reason' => $reason,
                    ],
                    context: $context,
                    module: 'face_biometrics'
                );

                return $profile->fresh(['person', 'recognitionModel', 'samples', 'enrolledBy']);
            });
        } catch (Throwable $e) {
            $this->compensateStorage($storagePath);
            throw $e;
        }
    }

    /**
     * Re-enroll face profile directly by FaceProfile.
     *
     * @throws FaceLifecycleException
     */
    public function reenrollByProfile(
        FaceProfile $profile,
        UploadedFile|string $imageInput,
        string $reason,
        ?User $actor = null,
        FaceSamplePose $pose = FaceSamplePose::FRONT,
        FaceSampleSource $source = FaceSampleSource::UPLOAD,
        ?AuditRequestContext $context = null
    ): FaceProfile {
        $person = $profile->person;
        if (! $person) {
            throw new FaceLifecycleException('Associated Person not found for FaceProfile.', 'PERSON_NOT_FOUND', 404);
        }

        return $this->reenroll($person, $imageInput, $reason, $actor, $pose, $source, $context);
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
