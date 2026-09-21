<?php

namespace App\Services\Face\DTO;

/**
 * Candidate DTO for potential duplicate face enrollments (FACE-011).
 */
readonly class FaceDuplicateCandidateDTO
{
    /**
     * @param  array{code: string, version: string, type: string}  $model
     */
    public function __construct(
        public ?string $duplicateReviewUuid,
        public string $candidateFaceProfileUuid,
        public string $candidatePersonUuid,
        public float $distance,
        public float $similarity,
        public string $reviewStatus,
        public array $model,
        public ?string $reviewedAt = null,
        public ?string $reviewNote = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'duplicate_review_uuid' => $this->duplicateReviewUuid,
            'candidate_face_profile_uuid' => $this->candidateFaceProfileUuid,
            'candidate_person_uuid' => $this->candidatePersonUuid,
            'distance' => $this->distance,
            'similarity' => $this->similarity,
            'review_status' => $this->reviewStatus,
            'model' => $this->model,
            'reviewed_at' => $this->reviewedAt,
            'review_note' => $this->reviewNote,
        ];
    }
}
