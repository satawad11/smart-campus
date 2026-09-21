<?php

namespace App\Services\Face\DTO;

/**
 * Result DTO for duplicate face review queries (FACE-011).
 *
 * Invariants:
 * - reviewOutcome is NO_CANDIDATE or REVIEW_REQUIRED.
 * - Does NOT emit recognition classifications (MATCH/UNKNOWN).
 * - Excludes raw vectors, internal numeric database IDs, and storage paths.
 */
readonly class FaceDuplicateReviewResult
{
    /**
     * @param  list<FaceDuplicateCandidateDTO>  $candidates
     */
    public function __construct(
        public string $sourceFaceProfileUuid,
        public string $sourcePersonUuid,
        public string $reviewOutcome,
        public bool $hasPotentialDuplicates,
        public float $threshold,
        public array $candidates,
        public int $candidateCount,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_face_profile_uuid' => $this->sourceFaceProfileUuid,
            'source_person_uuid' => $this->sourcePersonUuid,
            'review_outcome' => $this->reviewOutcome,
            'has_potential_duplicates' => $this->hasPotentialDuplicates,
            'threshold' => $this->threshold,
            'candidate_count' => $this->candidateCount,
            'candidates' => array_map(fn (FaceDuplicateCandidateDTO $c) => $c->toArray(), $this->candidates),
        ];
    }
}
