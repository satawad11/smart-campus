<?php

namespace App\Services\Face;

use App\Enums\FaceProfileStatus;
use JsonSerializable;

/**
 * Biometric vector search candidate result (FACE-008).
 *
 * Invariants:
 * - Public identifiers by UUID only (never exposes internal bigint IDs).
 * - Exposes distance and similarity metrics without making identity decisions.
 * - Free of recognition classifications (MATCH/REVIEW/UNKNOWN - FACE-013) or access decisions (ALLOW/REVIEW/DENY - Module 05).
 */
readonly class VectorSearchCandidate implements JsonSerializable
{
    public function __construct(
        public string $faceProfileUuid,
        public string $personUuid,
        public string $modelCode,
        public string $modelVersion,
        public float $distance,
        public float $similarity,
        public int $rank,
        public FaceProfileStatus|string $status,
    ) {}

    /**
     * @return array{
     *     face_profile_uuid: string,
     *     person_uuid: string,
     *     model_code: string,
     *     model_version: string,
     *     distance: float,
     *     similarity: float,
     *     rank: int,
     *     status: string
     * }
     */
    public function toArray(): array
    {
        return [
            'face_profile_uuid' => $this->faceProfileUuid,
            'person_uuid' => $this->personUuid,
            'model_code' => $this->modelCode,
            'model_version' => $this->modelVersion,
            'distance' => $this->distance,
            'similarity' => $this->similarity,
            'rank' => $this->rank,
            'status' => $this->status instanceof FaceProfileStatus ? $this->status->value : (string) $this->status,
        ];
    }

    /**
     * @return array{
     *     face_profile_uuid: string,
     *     person_uuid: string,
     *     model_code: string,
     *     model_version: string,
     *     distance: float,
     *     similarity: float,
     *     rank: int,
     *     status: string
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
