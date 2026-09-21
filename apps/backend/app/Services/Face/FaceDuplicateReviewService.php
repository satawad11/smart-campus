<?php

namespace App\Services\Face;

use App\Enums\AIModelType;
use App\Enums\AuditAction;
use App\Enums\DuplicateReviewStatus;
use App\Exceptions\Face\FaceEnrollmentException;
use App\Exceptions\Face\IncompatibleModelException;
use App\Models\AIModel;
use App\Models\FaceDuplicateReview;
use App\Models\FaceProfile;
use App\Models\Person;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use App\Services\Face\DTO\FaceDuplicateCandidateDTO;
use App\Services\Face\DTO\FaceDuplicateReviewResult;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative Duplicate-Face Review Service (FACE-011).
 *
 * Checks newly enrolled or verified face profiles against the database of enrolled faces
 * to flag potential duplicate identities for human review.
 *
 * Invariants:
 * - Directs vector candidate queries strictly through FaceEmbeddingRepository (pgvector).
 * - Operates strictly within the same exact FACE_EMBEDDING model and version space.
 * - Excludes the source profile from candidate results (self exclusion).
 * - Derived threshold comes from model configuration / metadata.
 * - Returns review outcome (NO_CANDIDATE vs REVIEW_REQUIRED); does NOT emit MATCH/UNKNOWN.
 * - Confirmation does NOT automatically merge Person, delete profiles, or execute access decisions.
 */
class FaceDuplicateReviewService
{
    public function __construct(
        protected FaceEmbeddingRepository $embeddingRepository,
        protected AuditService $auditService
    ) {}

    /**
     * Find potential duplicate face enrollment candidates for a target FaceProfile.
     *
     * @throws FaceEnrollmentException
     */
    public function findDuplicates(FaceProfile $sourceProfile, ?int $limit = 5): FaceDuplicateReviewResult
    {
        /** @var AIModel|null $aiModel */
        $aiModel = $sourceProfile->recognitionModel;

        if (! $aiModel) {
            throw new IncompatibleModelException(
                "FaceProfile [{$sourceProfile->uuid}] has no associated AI model."
            );
        }

        if ($aiModel->model_type !== AIModelType::FACE_EMBEDDING) {
            throw new IncompatibleModelException(
                "AI model [{$aiModel->code}] has incompatible model type [{$aiModel->model_type->value}]. Expected FACE_EMBEDDING."
            );
        }

        $rawEmbedding = $sourceProfile->embedding;
        if (empty($rawEmbedding)) {
            throw new FaceEnrollmentException(
                "FaceProfile [{$sourceProfile->uuid}] contains no vector embedding.",
                'EMBEDDING_MISSING',
                422
            );
        }

        $vector = $this->parseVector($rawEmbedding);
        $boundLimit = min(max($limit ?? 5, 1), FaceEmbeddingRepository::MAX_TOP_K);

        // Check feature flag
        if (config('face.duplicate_review.enabled') === false) {
            return new FaceDuplicateReviewResult(
                sourceFaceProfileUuid: $sourceProfile->uuid,
                sourcePersonUuid: $sourceProfile->person?->uuid ?? '',
                reviewOutcome: 'NO_CANDIDATE',
                hasPotentialDuplicates: false,
                threshold: 0.0,
                candidates: [],
                candidateCount: 0
            );
        }

        // Derive threshold from explicit policy/config or explicit model metadata (FACE-021 boundary)
        $similarityThreshold = $this->resolveDuplicateReviewThreshold($aiModel);

        // Query pgvector repository
        $allCandidates = $this->embeddingRepository->search(
            queryVector: $vector,
            model: $aiModel,
            topK: $boundLimit + 5 // Fetch slightly more to account for self-exclusion
        );

        $filteredCandidates = [];
        $sourcePerson = $sourceProfile->person;

        foreach ($allCandidates as $candidate) {
            // 1. Strict Self Exclusion
            if ($candidate->faceProfileUuid === $sourceProfile->uuid) {
                continue;
            }

            // 2. Similarity threshold evaluation for suspicious duplicate review
            if ($candidate->similarity < $similarityThreshold) {
                continue;
            }

            // Resolve target candidate profile
            /** @var FaceProfile|null $candidateProfile */
            $candidateProfile = FaceProfile::where('uuid', $candidate->faceProfileUuid)->first();
            if (! $candidateProfile) {
                continue;
            }

            /** @var Person|null $candidatePerson */
            $candidatePerson = $candidateProfile->person;

            // 3. Idempotently persist duplicate review record in DB
            /** @var FaceDuplicateReview $reviewRecord */
            $reviewRecord = FaceDuplicateReview::firstOrCreate(
                [
                    'source_face_profile_id' => $sourceProfile->id,
                    'candidate_face_profile_id' => $candidateProfile->id,
                    'recognition_model_id' => $aiModel->id,
                ],
                [
                    'distance' => $candidate->distance,
                    'similarity' => $candidate->similarity,
                    'review_status' => DuplicateReviewStatus::PENDING,
                ]
            );

            $filteredCandidates[] = new FaceDuplicateCandidateDTO(
                duplicateReviewUuid: $reviewRecord->uuid,
                candidateFaceProfileUuid: $candidate->faceProfileUuid,
                candidatePersonUuid: $candidatePerson?->uuid ?? '',
                distance: $candidate->distance,
                similarity: $candidate->similarity,
                reviewStatus: $reviewRecord->review_status->value,
                model: [
                    'code' => $aiModel->code,
                    'version' => $aiModel->version,
                    'type' => $aiModel->model_type->value,
                ],
                reviewedAt: $reviewRecord->reviewed_at?->toIso8601String(),
                reviewNote: $reviewRecord->review_note
            );

            if (count($filteredCandidates) >= $boundLimit) {
                break;
            }
        }

        $hasDuplicates = count($filteredCandidates) > 0;
        $reviewOutcome = $hasDuplicates ? 'REVIEW_REQUIRED' : 'NO_CANDIDATE';

        return new FaceDuplicateReviewResult(
            sourceFaceProfileUuid: $sourceProfile->uuid,
            sourcePersonUuid: $sourcePerson?->uuid ?? '',
            reviewOutcome: $reviewOutcome,
            hasPotentialDuplicates: $hasDuplicates,
            threshold: $similarityThreshold,
            candidates: $filteredCandidates,
            candidateCount: count($filteredCandidates)
        );
    }

    /**
     * Submit a human duplicate-review decision.
     */
    public function recordDecision(
        FaceDuplicateReview $review,
        DuplicateReviewStatus $decision,
        ?string $note = null,
        ?User $actor = null,
        ?AuditRequestContext $context = null
    ): FaceDuplicateReview {
        return DB::transaction(function () use ($review, $decision, $note, $actor, $context) {
            /** @var FaceDuplicateReview|null $lockedReview */
            $lockedReview = FaceDuplicateReview::where('id', $review->id)->lockForUpdate()->first();

            if (! $lockedReview) {
                throw new FaceEnrollmentException('Duplicate review record not found.', 'REVIEW_NOT_FOUND', 404);
            }

            $previousStatus = $lockedReview->review_status;

            $lockedReview->review_status = $decision;
            $lockedReview->reviewed_by_user_id = $actor?->id;
            $lockedReview->reviewed_at = now();
            $lockedReview->review_note = $note ? trim($note) : null;
            $lockedReview->save();

            $sourceProfile = $lockedReview->sourceProfile;
            $candidateProfile = $lockedReview->candidateProfile;

            // Record audit log
            $this->auditService->record(
                action: AuditAction::FACE_DUPLICATE_REVIEWED,
                actor: $actor,
                subjectType: 'FaceDuplicateReview',
                subjectUuid: $lockedReview->uuid,
                oldValues: ['review_status' => $previousStatus->value],
                newValues: [
                    'review_status' => $decision->value,
                    'source_face_profile_uuid' => $sourceProfile?->uuid,
                    'candidate_face_profile_uuid' => $candidateProfile?->uuid,
                    'distance' => $lockedReview->distance,
                    'similarity' => $lockedReview->similarity,
                    'review_note' => $lockedReview->review_note,
                ],
                metadata: [
                    'review_uuid' => $lockedReview->uuid,
                    'source_face_profile_uuid' => $sourceProfile?->uuid,
                    'candidate_face_profile_uuid' => $candidateProfile?->uuid,
                    'distance' => $lockedReview->distance,
                    'similarity' => $lockedReview->similarity,
                    'decision' => $decision->value,
                ],
                context: $context,
                module: 'face_biometrics'
            );

            return $lockedReview->fresh(['sourceProfile.person', 'candidateProfile.person', 'recognitionModel', 'reviewer']);
        });
    }

    /**
     * Parse raw string/array vector into array of floats.
     *
     * @return list<float>
     */
    protected function parseVector(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_map('floatval', $raw));
        }

        if (is_string($raw)) {
            $trimmed = trim($raw, "[] \t\n\r\0\x0B");
            if ($trimmed === '') {
                return [];
            }
            $parts = explode(',', $trimmed);

            return array_values(array_map(fn ($p) => (float) trim($p), $parts));
        }

        return [];
    }

    /**
     * Resolve the explicit duplicate review similarity threshold.
     *
     * Boundary note:
     * - FACE-011 consumes configured duplicate-review thresholds.
     * - FACE-021 owns human calibration/review/approval of pilot threshold values.
     * - Generic AIModel.threshold_high is model/calibration metadata, NOT duplicate review policy.
     * - There is NO silent hard-coded production fallback (such as 0.65).
     *
     * Threshold priority:
     * 1. Explicit duplicate-review policy/config (config('face.duplicate_review.similarity_threshold'))
     * 2. Explicit model metadata if specifically tagged ('duplicate_review_threshold')
     * 3. Fail closed by throwing FaceEnrollmentException with error code DUPLICATE_REVIEW_THRESHOLD_NOT_CONFIGURED.
     *
     * @throws FaceEnrollmentException
     */
    public function resolveDuplicateReviewThreshold(AIModel $model): float
    {
        $configThreshold = config('face.duplicate_review.similarity_threshold');
        if ($configThreshold !== null && is_numeric($configThreshold)) {
            $threshold = (float) $configThreshold;
            if ($threshold >= 0.0 && $threshold <= 1.0) {
                return $threshold;
            }
        }

        // Check if model has explicitly defined duplicate_review_threshold in its metadata array
        if (is_array($model->metadata) && isset($model->metadata['duplicate_review_threshold']) && is_numeric($model->metadata['duplicate_review_threshold'])) {
            $threshold = (float) $model->metadata['duplicate_review_threshold'];
            if ($threshold >= 0.0 && $threshold <= 1.0) {
                return $threshold;
            }
        }

        // Fail closed: generic threshold_high is NOT duplicate review policy
        throw new FaceEnrollmentException(
            "Duplicate review similarity threshold is not configured for model [{$model->code}]. Generic threshold_high cannot be used as duplicate review policy (FACE-021 boundary).",
            'DUPLICATE_REVIEW_THRESHOLD_NOT_CONFIGURED',
            422
        );
    }
}
