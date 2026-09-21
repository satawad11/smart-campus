<?php

namespace App\Services\Face\DTO;

/**
 * Perception pipeline result DTO (FACE-009).
 */
readonly class FacePipelineResult
{
    /**
     * @param  array<int, float>  $embedding
     * @param  list<string>  $rejectionReasons
     * @param  array<string, float>|null  $boundingBox
     */
    public function __construct(
        public int $faceCount,
        public string $modelCode,
        public string $modelVersion,
        public string $modelType,
        public int $embeddingDimension,
        public array $embedding,
        public float $qualityScore,
        public float $blurScore,
        public float $brightnessScore,
        public float $faceSizeScore,
        public float $poseScore,
        public ?float $yaw = null,
        public ?float $pitch = null,
        public ?float $roll = null,
        public bool $qualityAccepted = true,
        public array $rejectionReasons = [],
        public ?array $boundingBox = null,
    ) {}

    /**
     * Build DTO from AI perception service JSON response.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $quality = (array) ($data['quality'] ?? []);
        $bbox = isset($data['bounding_box']) ? (array) $data['bounding_box'] : null;

        return new self(
            faceCount: (int) ($data['face_count'] ?? 1),
            modelCode: (string) ($data['model_code'] ?? 'arcface-r50-v1'),
            modelVersion: (string) ($data['model_version'] ?? '1.0.0'),
            modelType: (string) ($data['model_type'] ?? 'FACE_EMBEDDING'),
            embeddingDimension: (int) ($data['embedding_dimension'] ?? 512),
            embedding: array_map('floatval', (array) ($data['embedding'] ?? [])),
            qualityScore: (float) ($quality['quality_score'] ?? 0.0),
            blurScore: (float) ($quality['blur_score'] ?? 0.0),
            brightnessScore: (float) ($quality['brightness_score'] ?? 0.0),
            faceSizeScore: (float) ($quality['face_size_score'] ?? 0.0),
            poseScore: (float) ($quality['pose_score'] ?? 0.0),
            yaw: isset($quality['yaw']) ? (float) $quality['yaw'] : null,
            pitch: isset($quality['pitch']) ? (float) $quality['pitch'] : null,
            roll: isset($quality['roll']) ? (float) $quality['roll'] : null,
            qualityAccepted: (bool) ($quality['accepted'] ?? true),
            rejectionReasons: array_values(array_map('strval', (array) ($quality['rejection_reasons'] ?? []))),
            boundingBox: $bbox,
        );
    }
}
