<?php

namespace App\Services\Face\DTO;

/**
 * Candidate result DTO for restricted face recognition diagnostic tests (FACE-012).
 *
 * Invariants:
 * - Excludes raw vectors, internal numeric database IDs, and storage paths.
 * - Does NOT contain MATCH/REVIEW/UNKNOWN classifications (FACE-013).
 * - Does NOT contain ALLOW/DENY access decisions (Module 05).
 */
readonly class FaceRecognitionTestCandidateDTO
{
    /**
     * @param  array{code: string, version: string, type: string}  $model
     */
    public function __construct(
        public string $candidateFaceProfileUuid,
        public string $candidatePersonUuid,
        public int $rank,
        public float $distance,
        public float $similarity,
        public array $model,
        public string $status
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'candidate_face_profile_uuid' => $this->candidateFaceProfileUuid,
            'candidate_person_uuid' => $this->candidatePersonUuid,
            'rank' => $this->rank,
            'distance' => $this->distance,
            'similarity' => $this->similarity,
            'model' => $this->model,
            'status' => $this->status,
        ];
    }
}
