<?php

namespace App\Services\Face;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\AuditAction;
use App\Exceptions\Face\FaceEnrollmentException;
use App\Exceptions\Face\FacePipelineUnavailableException;
use App\Exceptions\Face\FaceQualityRejectedException;
use App\Exceptions\Face\IncompatibleModelException;
use App\Exceptions\Face\InvalidImageException;
use App\Exceptions\Face\MultipleFacesDetectedException;
use App\Exceptions\Face\NoFaceDetectedException;
use App\Models\AIModel;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use App\Services\Face\DTO\FaceRecognitionTestCandidateDTO;
use App\Services\Face\DTO\FaceRecognitionTestResult;
use Illuminate\Http\UploadedFile;

/**
 * Service for Restricted Face Recognition Diagnostic Testing (FACE-012).
 *
 * Provides a restricted diagnostic endpoint for authorized operators to inspect
 * candidate nearest-neighbor embeddings for a supplied test image.
 *
 * Invariants:
 * - Executes perception pipeline (detection -> alignment -> quality -> embedding).
 * - Enforces face count (strictly 1 face required; 0 or >1 rejected).
 * - Enforces quality gate (technically unusable face input is rejected).
 * - Performs vector similarity search strictly against the same exact FACE_EMBEDDING model/version.
 * - Diagnostic candidate evidence only (rank, distance, similarity).
 * - Zero recognition classification (MATCH/REVIEW/UNKNOWN owned by FACE-013).
 * - Zero access decisions (ALLOW/DENY owned by Module 05).
 * - Zero persistent biometric writes (no FaceProfile, no FaceSample, no vector storage).
 * - Zero person, student, or user identity mutations.
 * - Zero duplicate review triggers (FACE-011).
 * - Ephemeral processing with zero long-term staging or leaking of raw biometric data.
 */
class FaceRecognitionTestService
{
    public function __construct(
        protected FacePipelineClient $pipelineClient,
        protected FaceEmbeddingRepository $embeddingRepository,
        protected AuditService $auditService
    ) {}

    /**
     * Execute a restricted face recognition test.
     *
     * @param  UploadedFile|string  $imageInput  Uploaded file or raw binary image bytes
     * @param  int|null  $topK  Number of top nearest candidates to return (1-50)
     * @param  User|null  $actor  Authenticated operator executing the test
     * @param  AuditRequestContext|null  $context  Request context for auditing
     *
     * @throws NoFaceDetectedException
     * @throws MultipleFacesDetectedException
     * @throws FaceQualityRejectedException
     * @throws InvalidImageException
     * @throws IncompatibleModelException
     * @throws FacePipelineUnavailableException
     * @throws FaceEnrollmentException
     */
    public function executeTest(
        UploadedFile|string $imageInput,
        ?int $topK = null,
        ?User $actor = null,
        ?AuditRequestContext $context = null
    ): FaceRecognitionTestResult {
        // 1. Extract image bytes and validate MIME type
        [$imageBytes, $mimeType] = $this->extractImageBytes($imageInput);

        // 2. Execute AI perception pipeline
        $pipelineResult = $this->pipelineClient->processEnrollment(
            imageBytes: $imageBytes,
            mimeType: $mimeType,
            pose: 'FRONT',
            source: 'UPLOAD'
        );

        // 3. Enforce face count: strictly 1 face
        if ($pipelineResult->faceCount === 0) {
            throw new NoFaceDetectedException('No face detected in the supplied recognition test image.');
        }

        if ($pipelineResult->faceCount > 1) {
            throw new MultipleFacesDetectedException(
                $pipelineResult->faceCount,
                "Recognition test rejected: {$pipelineResult->faceCount} faces detected. Exactly 1 face is required."
            );
        }

        // 4. Enforce quality gate
        if (! $pipelineResult->qualityAccepted) {
            throw new FaceQualityRejectedException(
                $pipelineResult->rejectionReasons,
                [
                    'quality_score' => $pipelineResult->qualityScore,
                    'blur_score' => $pipelineResult->blurScore,
                    'brightness_score' => $pipelineResult->brightnessScore,
                    'pose_score' => $pipelineResult->poseScore,
                ],
                'Recognition test rejected: face sample does not meet quality requirements.'
            );
        }

        // 5. Resolve active AI embedding model
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
            throw new IncompatibleModelException(
                'No active AI model of type FACE_EMBEDDING found in registry for recognition test.'
            );
        }

        // 6. Execute candidate vector search (bounded top_k)
        $boundTopK = min(max($topK ?? FaceEmbeddingRepository::DEFAULT_TOP_K, 1), FaceEmbeddingRepository::MAX_TOP_K);

        $searchResults = $this->embeddingRepository->search(
            queryVector: $pipelineResult->embedding,
            model: $aiModel,
            topK: $boundTopK
        );

        // 7. Map search results to candidate DTOs
        $candidates = [];
        foreach ($searchResults as $candidate) {
            $statusValue = is_object($candidate->status) && enum_exists(get_class($candidate->status))
                ? $candidate->status->value
                : (string) $candidate->status;

            $candidates[] = new FaceRecognitionTestCandidateDTO(
                candidateFaceProfileUuid: $candidate->faceProfileUuid,
                candidatePersonUuid: $candidate->personUuid,
                rank: $candidate->rank,
                distance: $candidate->distance,
                similarity: $candidate->similarity,
                model: [
                    'code' => $candidate->modelCode,
                    'version' => $candidate->modelVersion,
                    'type' => AIModelType::FACE_EMBEDDING->value,
                ],
                status: $statusValue
            );
        }

        $result = new FaceRecognitionTestResult(
            model: [
                'code' => $aiModel->code,
                'version' => $aiModel->version,
                'type' => $aiModel->model_type->value,
            ],
            faceCount: $pipelineResult->faceCount,
            qualityScore: $pipelineResult->qualityScore,
            topK: $boundTopK,
            candidateCount: count($candidates),
            candidates: $candidates,
            executedAt: now()->toIso8601String()
        );

        // 8. Record audit log (safe metadata only; NO raw vectors or image bytes)
        $this->auditService->record(
            action: AuditAction::FACE_RECOGNITION_TESTED,
            actor: $actor,
            subjectType: 'FaceRecognitionTest',
            subjectUuid: null,
            oldValues: null,
            newValues: null,
            metadata: [
                'model_code' => $aiModel->code,
                'model_version' => $aiModel->version,
                'top_k' => $boundTopK,
                'candidate_count' => count($candidates),
                'quality_score' => $pipelineResult->qualityScore,
            ],
            context: $context,
            module: 'face_biometrics'
        );

        return $result;
    }

    /**
     * Extract image bytes and validate MIME type.
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidImageException
     */
    protected function extractImageBytes(UploadedFile|string $input): array
    {
        if ($input instanceof UploadedFile) {
            if (! $input->isValid()) {
                throw new InvalidImageException('Uploaded recognition test image file is invalid or corrupted.');
            }

            $mimeType = $input->getMimeType() ?? 'image/jpeg';
            $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

            if (! in_array(strtolower($mimeType), $allowedMimes, true)) {
                throw new InvalidImageException(
                    "Unsupported image MIME type [{$mimeType}]. Allowed types: JPEG, PNG, WEBP."
                );
            }

            $bytes = file_get_contents($input->getRealPath());
            if ($bytes === false || strlen($bytes) === 0) {
                throw new InvalidImageException('Failed to read uploaded recognition test image content.');
            }

            return [$bytes, $mimeType];
        }

        if (strlen($input) === 0) {
            throw new InvalidImageException('Supplied image content is empty.');
        }

        return [$input, 'image/jpeg'];
    }
}
