<?php

namespace App\Services\Face\DTO;

/**
 * Result DTO for restricted recognition diagnostic tests (FACE-012).
 *
 * Invariants:
 * - Diagnostic candidate evidence only.
 * - Does NOT emit recognition classifications (MATCH/REVIEW/UNKNOWN).
 * - Does NOT emit access decisions (ALLOW/DENY).
 * - Excludes raw vectors, internal numeric database IDs, and storage paths.
 */
readonly class FaceRecognitionTestResult
{
    /**
     * @param  array{code: string, version: string, type: string}  $model
     * @param  list<FaceRecognitionTestCandidateDTO>  $candidates
     */
    public function __construct(
        public array $model,
        public int $faceCount,
        public float $qualityScore,
        public int $topK,
        public int $candidateCount,
        public array $candidates,
        public string $executedAt
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'face_count' => $this->faceCount,
            'quality_score' => $this->qualityScore,
            'top_k' => $this->topK,
            'candidate_count' => $this->candidateCount,
            'candidates' => array_map(fn (FaceRecognitionTestCandidateDTO $c) => $c->toArray(), $this->candidates),
            'executed_at' => $this->executedAt,
        ];
    }
}
