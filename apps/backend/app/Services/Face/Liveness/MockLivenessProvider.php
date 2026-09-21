<?php

namespace App\Services\Face\Liveness;

use App\Enums\FaceLivenessStatus;
use App\Exceptions\Face\InvalidImageException;
use Illuminate\Http\UploadedFile;

/**
 * Deterministic Mock Liveness Provider for test isolation and pilots.
 */
class MockLivenessProvider implements FaceLivenessProviderInterface
{
    public function __construct(
        protected float $defaultScore = 0.95,
        protected FaceLivenessStatus $defaultStatus = FaceLivenessStatus::REAL
    ) {}

    public function evaluate(UploadedFile|string $imageBytes, array $options = []): FaceLivenessResultDTO
    {
        if ($imageBytes instanceof UploadedFile) {
            $rawBytes = (string) $imageBytes->get();
        } else {
            $rawBytes = $imageBytes;
        }

        if (empty($rawBytes)) {
            throw new InvalidImageException('Liveness image payload cannot be empty.');
        }

        $score = (float) ($options['override_score'] ?? $this->defaultScore);
        $status = $options['override_status'] ?? ($score >= 0.70 ? FaceLivenessStatus::REAL : ($score < 0.40 ? FaceLivenessStatus::SPOOF : FaceLivenessStatus::UNCERTAIN));

        return new FaceLivenessResultDTO(
            isLive: $status === FaceLivenessStatus::REAL,
            status: $status,
            livenessScore: $score,
            confidence: 0.98,
            method: 'MOCK_PAD',
            rejectionReasons: $status === FaceLivenessStatus::REAL ? [] : ['PRESENTATION_ATTACK_SUSPECTED'],
            details: ['mock' => true]
        );
    }
}
