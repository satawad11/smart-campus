<?php

namespace App\Services\Face;

use App\Enums\AIModelType;
use App\Enums\AuditAction;
use App\Enums\FaceProfileStatus;
use App\Exceptions\Face\FaceVerificationException;
use App\Models\AIModel;
use App\Models\FaceProfile;
use App\Models\FaceSample;
use App\Models\Person;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Authoritative Face Enrollment Verification Service (FACE-010).
 *
 * Verifies that an enrolled biometric profile is technically coherent,
 * complete, and usable. Does not perform duplicate search, recognition
 * classification, or access control decisions.
 */
class FaceEnrollmentVerificationService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Verify an enrolled FaceProfile.
     *
     * @throws FaceVerificationException
     */
    public function verify(
        FaceProfile $faceProfile,
        ?User $actor = null,
        ?AuditRequestContext $context = null
    ): DTO\FaceVerificationResult {
        return DB::transaction(function () use ($faceProfile, $actor, $context) {
            // 1. Lock profile and person records for concurrency control
            /** @var FaceProfile|null $lockedProfile */
            $lockedProfile = FaceProfile::where('id', $faceProfile->id)->lockForUpdate()->first();

            if (! $lockedProfile) {
                throw new FaceVerificationException(
                    "FaceProfile [{$faceProfile->uuid}] not found.",
                    'FACE_PROFILE_NOT_FOUND',
                    404
                );
            }

            /** @var Person|null $lockedPerson */
            $lockedPerson = Person::where('id', $lockedProfile->person_id)->lockForUpdate()->first();

            if (! $lockedPerson) {
                throw new FaceVerificationException(
                    "Associated Person for FaceProfile [{$lockedProfile->uuid}] not found.",
                    'PERSON_NOT_FOUND',
                    404
                );
            }

            // 2. Lifecycle Status Invariants
            if ($lockedProfile->status === FaceProfileStatus::REVOKED) {
                throw new FaceVerificationException(
                    "FaceProfile [{$lockedProfile->uuid}] is REVOKED and cannot be verified.",
                    'FACE_PROFILE_REVOKED',
                    422
                );
            }

            // 3. Sample Integrity Checks
            $samples = FaceSample::where('face_profile_id', $lockedProfile->id)->get();

            if ($samples->isEmpty()) {
                throw new FaceVerificationException(
                    "FaceProfile [{$lockedProfile->uuid}] has no biometric samples.",
                    'FACE_SAMPLE_MISSING',
                    422
                );
            }

            /** @var FaceSample $primarySample */
            $primarySample = $samples->first();

            foreach ($samples as $sample) {
                if (! $sample->accepted) {
                    throw new FaceVerificationException(
                        "Biometric sample [{$sample->uuid}] was not accepted during enrollment.",
                        'FACE_SAMPLE_NOT_ACCEPTED',
                        422
                    );
                }

                // Verify required quality scores exist and are valid finite floats [0.0, 1.0]
                $scores = [
                    'quality_score' => $sample->quality_score,
                    'blur_score' => $sample->blur_score,
                    'brightness_score' => $sample->brightness_score,
                    'face_size_score' => $sample->face_size_score,
                ];

                foreach ($scores as $scoreName => $scoreValue) {
                    if ($scoreValue === null || ! is_numeric($scoreValue) || ! is_finite((float) $scoreValue) || (float) $scoreValue < 0.0 || (float) $scoreValue > 1.0) {
                        throw new FaceVerificationException(
                            "Sample [{$sample->uuid}] contains invalid {$scoreName}: [{$scoreValue}].",
                            'FACE_QUALITY_INVALID',
                            422
                        );
                    }
                }

                // Verify pose angles are finite floats if present
                $poseAngles = ['yaw' => $sample->yaw, 'pitch' => $sample->pitch, 'roll' => $sample->roll];
                foreach ($poseAngles as $angleName => $angleVal) {
                    if ($angleVal !== null && (! is_numeric($angleVal) || ! is_finite((float) $angleVal))) {
                        throw new FaceVerificationException(
                            "Sample [{$sample->uuid}] contains non-finite pose angle {$angleName}.",
                            'FACE_QUALITY_INVALID',
                            422
                        );
                    }
                }

                // 4. Storage Object Integrity
                if (empty($sample->image_path)) {
                    throw new FaceVerificationException(
                        "Sample [{$sample->uuid}] missing image path.",
                        'STORAGE_OBJECT_MISSING',
                        422
                    );
                }

                try {
                    $exists = Storage::disk('face_samples')->exists($sample->image_path);
                } catch (Throwable $e) {
                    throw new FaceVerificationException(
                        "Failed to inspect private storage for sample [{$sample->uuid}]: {$e->getMessage()}",
                        'STORAGE_OBJECT_MISSING',
                        422
                    );
                }

                if (! $exists) {
                    throw new FaceVerificationException(
                        "Biometric sample image missing from private storage: [{$sample->image_path}].",
                        'STORAGE_OBJECT_MISSING',
                        422
                    );
                }

                // Checksum integrity verification
                if (! empty($sample->checksum)) {
                    $objectBytes = (string) Storage::disk('face_samples')->get($sample->image_path);
                    $calculatedChecksum = hash('sha256', $objectBytes);
                    if ($calculatedChecksum !== $sample->checksum) {
                        throw new FaceVerificationException(
                            "Biometric sample image checksum mismatch for sample [{$sample->uuid}].",
                            'CHECKSUM_MISMATCH',
                            422
                        );
                    }
                }
            }

            // 5. Vector Embedding Integrity Checks
            $rawEmbedding = $lockedProfile->embedding;

            if ($rawEmbedding === null || $rawEmbedding === '') {
                throw new FaceVerificationException(
                    "FaceProfile [{$lockedProfile->uuid}] contains no vector embedding.",
                    'EMBEDDING_MISSING',
                    422
                );
            }

            $vector = $this->normalizeEmbeddingVector($rawEmbedding);
            $expectedDim = (int) ($lockedProfile->embedding_dimension ?? 512);

            if (count($vector) !== $expectedDim || $expectedDim !== 512) {
                throw new FaceVerificationException(
                    "FaceProfile [{$lockedProfile->uuid}] embedding dimension mismatch. Expected 512, got ".count($vector).'.',
                    'EMBEDDING_INVALID',
                    422
                );
            }

            $normSum = 0.0;
            foreach ($vector as $val) {
                if (! is_finite($val)) {
                    throw new FaceVerificationException(
                        "FaceProfile [{$lockedProfile->uuid}] embedding contains non-finite values (NaN/Inf).",
                        'EMBEDDING_INVALID',
                        422
                    );
                }
                $normSum += $val * $val;
            }

            if (sqrt($normSum) < 0.0001) {
                throw new FaceVerificationException(
                    "FaceProfile [{$lockedProfile->uuid}] embedding has degenerate zero norm.",
                    'EMBEDDING_INVALID',
                    422
                );
            }

            // 6. AI Model Provenance & Compatibility
            /** @var AIModel|null $aiModel */
            $aiModel = AIModel::find($lockedProfile->recognition_model_id);

            if (! $aiModel) {
                throw new FaceVerificationException(
                    "Associated AI model [ID: {$lockedProfile->recognition_model_id}] not found in registry.",
                    'MODEL_NOT_FOUND',
                    422
                );
            }

            if ($aiModel->model_type !== AIModelType::FACE_EMBEDDING) {
                throw new FaceVerificationException(
                    "Model type [{$aiModel->model_type->value}] is incompatible with face embedding verification. Expected FACE_EMBEDDING.",
                    'MODEL_MISMATCH',
                    422
                );
            }

            if ($aiModel->embedding_dimension !== null && (int) $aiModel->embedding_dimension !== $expectedDim) {
                throw new FaceVerificationException(
                    "AI model embedding dimension [{$aiModel->embedding_dimension}] does not match face profile dimension [{$expectedDim}].",
                    'EMBEDDING_INVALID',
                    422
                );
            }

            // 7. Atomic Profile State Transition & Idempotent Audit
            $previousStatus = $lockedProfile->status;
            $isInitialVerification = ($previousStatus === FaceProfileStatus::PENDING);

            if ($isInitialVerification) {
                $lockedProfile->status = FaceProfileStatus::ACTIVE;
                $lockedProfile->save();

                // Record authoritative semantic FACE_ENROLLMENT_VERIFIED audit only on initial state transition
                $this->auditService->record(
                    action: AuditAction::FACE_ENROLLMENT_VERIFIED,
                    actor: $actor,
                    subjectType: 'FaceProfile',
                    subjectUuid: $lockedProfile->uuid,
                    oldValues: ['status' => $previousStatus->value],
                    newValues: [
                        'status' => $lockedProfile->status->value,
                        'person_uuid' => $lockedPerson->uuid,
                        'sample_count' => $samples->count(),
                        'model_code' => $aiModel->code,
                        'model_version' => $aiModel->version,
                        'quality_score' => (float) $primarySample->quality_score,
                    ],
                    metadata: [
                        'person_uuid' => $lockedPerson->uuid,
                        'face_profile_uuid' => $lockedProfile->uuid,
                        'sample_uuid' => $primarySample->uuid,
                        'sample_count' => $samples->count(),
                        'model_code' => $aiModel->code,
                        'model_version' => $aiModel->version,
                        'quality_score' => (float) $primarySample->quality_score,
                    ],
                    context: $context,
                    module: 'face_biometrics'
                );
            }

            $verifiedAt = now()->toIso8601String();

            return new DTO\FaceVerificationResult(
                verified: true,
                personUuid: $lockedPerson->uuid,
                faceProfileUuid: $lockedProfile->uuid,
                status: $lockedProfile->status->value,
                sampleCount: $samples->count(),
                model: [
                    'code' => $aiModel->code,
                    'version' => $aiModel->version,
                    'type' => $aiModel->model_type->value,
                ],
                quality: [
                    'quality_score' => (float) $primarySample->quality_score,
                    'blur_score' => (float) $primarySample->blur_score,
                    'brightness_score' => (float) $primarySample->brightness_score,
                    'face_size_score' => (float) $primarySample->face_size_score,
                ],
                verifiedAt: $verifiedAt
            );
        });
    }

    /**
     * Normalize stored vector representation (string or array) into list of floats.
     *
     * @return list<float>
     */
    protected function normalizeEmbeddingVector(mixed $rawEmbedding): array
    {
        if (is_array($rawEmbedding)) {
            return array_values(array_map('floatval', $rawEmbedding));
        }

        if (is_string($rawEmbedding)) {
            // Trim brackets e.g. "[0.04, 0.05, ...]"
            $trimmed = trim($rawEmbedding, "[] \t\n\r\0\x0B");
            if ($trimmed === '') {
                return [];
            }
            $parts = explode(',', $trimmed);

            return array_values(array_map(fn ($p) => (float) trim($p), $parts));
        }

        return [];
    }
}
