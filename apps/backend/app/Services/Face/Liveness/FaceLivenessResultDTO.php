<?php

namespace App\Services\Face\Liveness;

use App\Enums\FaceLivenessStatus;

/**
 * Presentation Attack Detection (PAD) / Liveness Result DTO (FACE-015).
 */
readonly class FaceLivenessResultDTO
{
    /**
     * @param  list<string>  $rejectionReasons
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public bool $isLive,
        public FaceLivenessStatus $status,
        public float $livenessScore,
        public float $confidence,
        public string $method,
        public array $rejectionReasons = [],
        public array $details = []
    ) {}

    /**
     * Build DTO from AI perception service JSON response.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $statusStr = strtoupper((string) ($data['status'] ?? 'UNCERTAIN'));
        $status = FaceLivenessStatus::tryFrom($statusStr) ?? FaceLivenessStatus::UNCERTAIN;

        return new self(
            isLive: (bool) ($data['is_live'] ?? ($status === FaceLivenessStatus::REAL)),
            status: $status,
            livenessScore: (float) ($data['liveness_score'] ?? 0.0),
            confidence: (float) ($data['confidence'] ?? 0.95),
            method: (string) ($data['method'] ?? 'PASSIVE_TEXTURE'),
            rejectionReasons: array_values(array_map('strval', (array) ($data['rejection_reasons'] ?? []))),
            details: (array) ($data['details'] ?? [])
        );
    }
}
