<?php

namespace App\Services\Face\DTO;

use App\Enums\FaceRecognitionClassification;

/**
 * Recognition Classification Result DTO (FACE-013).
 *
 * Invariants:
 * - Emits ONLY MATCH, REVIEW, or UNKNOWN.
 * - Does NOT emit ALLOW, REVIEW, or DENY access decisions.
 * - Masks raw embeddings, database internal IDs, and storage paths.
 */
readonly class FaceRecognitionClassificationResult
{
    /**
     * @param  list<FaceRecognitionTestCandidateDTO>  $candidates
     * @param  list<string>  $reasons
     * @param  array{match_threshold: float, review_threshold: float, ambiguity_margin: float}  $thresholds
     * @param  array{code: string, version: string, type: string}  $model
     */
    public function __construct(
        public FaceRecognitionClassification $classification,
        public ?FaceRecognitionTestCandidateDTO $matchedCandidate,
        public array $candidates,
        public array $reasons,
        public array $thresholds,
        public array $model,
        public string $executedAt
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'classification' => $this->classification->value,
            'matched_candidate' => $this->matchedCandidate?->toArray(),
            'candidate_count' => count($this->candidates),
            'candidates' => array_map(fn (FaceRecognitionTestCandidateDTO $c) => $c->toArray(), $this->candidates),
            'reasons' => $this->reasons,
            'thresholds' => $this->thresholds,
            'model' => $this->model,
            'executed_at' => $this->executedAt,
        ];
    }
}
