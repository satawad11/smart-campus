<?php

namespace App\Services\Face\DTO;

/**
 * Verification result DTO (FACE-010).
 *
 * Encapsulates verified biometric profile metadata while guaranteeing
 * that raw embeddings, storage paths, and internal numeric database IDs remain hidden.
 */
readonly class FaceVerificationResult
{
    /**
     * @param  array{code: string, version: string, type: string}  $model
     * @param  array{quality_score: float, blur_score: float, brightness_score: float, face_size_score: float}  $quality
     */
    public function __construct(
        public bool $verified,
        public string $personUuid,
        public string $faceProfileUuid,
        public string $status,
        public int $sampleCount,
        public array $model,
        public array $quality,
        public string $verifiedAt,
    ) {}

    /**
     * Convert DTO to a safe array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'verified' => $this->verified,
            'person_uuid' => $this->personUuid,
            'face_profile_uuid' => $this->faceProfileUuid,
            'status' => $this->status,
            'sample_count' => $this->sampleCount,
            'model' => $this->model,
            'quality' => $this->quality,
            'verified_at' => $this->verifiedAt,
        ];
    }
}
