<?php

namespace App\Services\Face;

use App\Enums\AIModelType;
use App\Enums\FaceRecognitionClassification;
use App\Exceptions\Face\FaceEnrollmentException;
use App\Models\AIModel;
use App\Services\Face\DTO\FaceRecognitionClassificationResult;
use App\Services\Face\DTO\FaceRecognitionTestCandidateDTO;
use Illuminate\Support\Collection;

/**
 * Recognition Classification Service (FACE-013).
 *
 * Evaluates nearest-neighbor candidate biometric evidence against approved recognition
 * policy thresholds to emit authoritative recognition classifications:
 * - MATCH: Single high-confidence candidate above match threshold without ambiguity.
 * - REVIEW: Candidate in review band OR top candidates ambiguous within ambiguity margin.
 * - UNKNOWN: No candidate above review threshold.
 *
 * Invariants:
 * - This represents biometric recognition classification only.
 * - It does NOT decide access control (ALLOW/REVIEW/DENY is owned by Module 05).
 * - Thresholds must be explicitly configured in production policy (FACE-021 boundary).
 * - Generic AIModel threshold_high is NOT automatically treated as recognition policy.
 * - Fails closed if thresholds are not explicitly configured.
 */
class FaceRecognitionClassificationService
{
    /**
     * Classify candidate search results according to explicit recognition policy.
     *
     * @param  Collection<int, VectorSearchCandidate>|list<FaceRecognitionTestCandidateDTO>  $candidates
     *
     * @throws FaceEnrollmentException
     */
    public function classify(Collection|array $candidates, AIModel $model): FaceRecognitionClassificationResult
    {
        $thresholds = $this->resolveThresholds($model);
        $matchThreshold = $thresholds['match_threshold'];
        $reviewThreshold = $thresholds['review_threshold'];
        $ambiguityMargin = $thresholds['ambiguity_margin'];

        $candidateDTOs = $this->normalizeCandidates($candidates, $model);
        $candidateCount = count($candidateDTOs);

        // Case 1: No candidates found
        if ($candidateCount === 0) {
            return new FaceRecognitionClassificationResult(
                classification: FaceRecognitionClassification::UNKNOWN,
                matchedCandidate: null,
                candidates: [],
                reasons: ['NO_CANDIDATES_FOUND'],
                thresholds: $thresholds,
                model: [
                    'code' => $model->code,
                    'version' => $model->version,
                    'type' => $model->model_type->value,
                ],
                executedAt: now()->toIso8601String()
            );
        }

        /** @var FaceRecognitionTestCandidateDTO $topCandidate */
        $topCandidate = $candidateDTOs[0];

        // Case 2: Top candidate similarity below review threshold
        if ($topCandidate->similarity < $reviewThreshold) {
            return new FaceRecognitionClassificationResult(
                classification: FaceRecognitionClassification::UNKNOWN,
                matchedCandidate: null,
                candidates: $candidateDTOs,
                reasons: ['NO_CANDIDATE_ABOVE_THRESHOLD'],
                thresholds: $thresholds,
                model: [
                    'code' => $model->code,
                    'version' => $model->version,
                    'type' => $model->model_type->value,
                ],
                executedAt: now()->toIso8601String()
            );
        }

        // Case 3: Top candidate similarity in review band (reviewThreshold <= sim < matchThreshold)
        if ($topCandidate->similarity < $matchThreshold) {
            return new FaceRecognitionClassificationResult(
                classification: FaceRecognitionClassification::REVIEW,
                matchedCandidate: null,
                candidates: $candidateDTOs,
                reasons: ['SIMILARITY_IN_REVIEW_BAND'],
                thresholds: $thresholds,
                model: [
                    'code' => $model->code,
                    'version' => $model->version,
                    'type' => $model->model_type->value,
                ],
                executedAt: now()->toIso8601String()
            );
        }

        // Case 4: Top candidate similarity >= matchThreshold.
        // Check for ambiguity with second candidate
        if ($candidateCount >= 2) {
            /** @var FaceRecognitionTestCandidateDTO $secondCandidate */
            $secondCandidate = $candidateDTOs[1];
            $margin = $topCandidate->similarity - $secondCandidate->similarity;

            // If second candidate is also above review threshold and the margin is too small
            if ($secondCandidate->similarity >= $reviewThreshold && $margin < $ambiguityMargin) {
                return new FaceRecognitionClassificationResult(
                    classification: FaceRecognitionClassification::REVIEW,
                    matchedCandidate: null,
                    candidates: $candidateDTOs,
                    reasons: ['AMBIGUOUS_CANDIDATES'],
                    thresholds: $thresholds,
                    model: [
                        'code' => $model->code,
                        'version' => $model->version,
                        'type' => $model->model_type->value,
                    ],
                    executedAt: now()->toIso8601String()
                );
            }
        }

        // Case 5: Clear unambiguous MATCH
        return new FaceRecognitionClassificationResult(
            classification: FaceRecognitionClassification::MATCH,
            matchedCandidate: $topCandidate,
            candidates: $candidateDTOs,
            reasons: ['HIGH_SIMILARITY_MATCH'],
            thresholds: $thresholds,
            model: [
                'code' => $model->code,
                'version' => $model->version,
                'type' => $model->model_type->value,
            ],
            executedAt: now()->toIso8601String()
        );
    }

    /**
     * Resolve explicit recognition policy thresholds.
     *
     * @return array{match_threshold: float, review_threshold: float, ambiguity_margin: float}
     *
     * @throws FaceEnrollmentException
     */
    public function resolveThresholds(AIModel $model): array
    {
        $match = config('face.recognition.match_threshold');
        $review = config('face.recognition.review_threshold');
        $margin = config('face.recognition.ambiguity_margin', 0.05);

        // Check explicit model metadata if config is unconfigured
        if ($match === null && is_array($model->metadata) && isset($model->metadata['recognition_match_threshold'])) {
            $match = $model->metadata['recognition_match_threshold'];
        }

        if ($review === null && is_array($model->metadata) && isset($model->metadata['recognition_review_threshold'])) {
            $review = $model->metadata['recognition_review_threshold'];
        }

        if ($match === null || ! is_numeric($match) || $review === null || ! is_numeric($review)) {
            throw new FaceEnrollmentException(
                "Recognition policy thresholds are not configured for model [{$model->code}]. Generic AIModel threshold_high cannot be used without explicit configuration (FACE-021 boundary).",
                'RECOGNITION_POLICY_NOT_CONFIGURED',
                422
            );
        }

        $matchFloat = (float) $match;
        $reviewFloat = (float) $review;
        $marginFloat = (float) $margin;

        if ($matchFloat < 0.0 || $matchFloat > 1.0 || $reviewFloat < 0.0 || $reviewFloat > 1.0) {
            throw new FaceEnrollmentException(
                'Recognition policy thresholds must be between 0.0 and 1.0.',
                'INVALID_RECOGNITION_THRESHOLDS',
                422
            );
        }

        if ($matchFloat < $reviewFloat) {
            throw new FaceEnrollmentException(
                "Match threshold ({$matchFloat}) cannot be less than review threshold ({$reviewFloat}).",
                'INVALID_RECOGNITION_THRESHOLDS',
                422
            );
        }

        return [
            'match_threshold' => $matchFloat,
            'review_threshold' => $reviewFloat,
            'ambiguity_margin' => $marginFloat,
        ];
    }

    /**
     * Normalize candidates into list of FaceRecognitionTestCandidateDTOs.
     *
     * @param  Collection<int, VectorSearchCandidate>|list<FaceRecognitionTestCandidateDTO>  $candidates
     * @return list<FaceRecognitionTestCandidateDTO>
     */
    protected function normalizeCandidates(Collection|array $candidates, AIModel $model): array
    {
        $dtos = [];
        $items = $candidates instanceof Collection ? $candidates->all() : $candidates;

        foreach ($items as $index => $item) {
            if ($item instanceof FaceRecognitionTestCandidateDTO) {
                $dtos[] = $item;
            } elseif ($item instanceof VectorSearchCandidate) {
                $statusValue = is_object($item->status) && enum_exists(get_class($item->status))
                    ? $item->status->value
                    : (string) $item->status;

                $dtos[] = new FaceRecognitionTestCandidateDTO(
                    candidateFaceProfileUuid: $item->faceProfileUuid,
                    candidatePersonUuid: $item->personUuid,
                    rank: $item->rank ?? ($index + 1),
                    distance: $item->distance,
                    similarity: $item->similarity,
                    model: [
                        'code' => $item->modelCode,
                        'version' => $item->modelVersion,
                        'type' => AIModelType::FACE_EMBEDDING->value,
                    ],
                    status: $statusValue
                );
            }
        }

        return $dtos;
    }
}
