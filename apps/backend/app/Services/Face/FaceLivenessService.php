<?php

namespace App\Services\Face;

use App\Enums\FaceLivenessStatus;
use App\Exceptions\Face\FacePipelineUnavailableException;
use App\Services\Face\Liveness\AiServiceLivenessProvider;
use App\Services\Face\Liveness\FaceLivenessProviderInterface;
use App\Services\Face\Liveness\FaceLivenessResultDTO;
use App\Services\Face\Liveness\MockLivenessProvider;
use Illuminate\Http\UploadedFile;

/**
 * Domain Service for Presentation Attack Detection / Face Liveness (FACE-015, docs/07-module-03-face-ai.md).
 *
 * Provides standalone liveness analysis and scoring, separate from recognition and access policy.
 */
class FaceLivenessService
{
    protected FaceLivenessProviderInterface $provider;

    public function __construct(?FaceLivenessProviderInterface $provider = null)
    {
        if ($provider) {
            $this->provider = $provider;
        } else {
            $providerType = config('face.liveness.provider', 'ai_service');
            if ($providerType === 'mock') {
                $this->provider = new MockLivenessProvider;
            } else {
                $baseUrl = config('services.ai.url', env('AI_SERVICE_URL', 'http://127.0.0.1:8001'));
                $this->provider = new AiServiceLivenessProvider(baseUrl: (string) $baseUrl);
            }
        }
    }

    public function setProvider(FaceLivenessProviderInterface $provider): void
    {
        $this->provider = $provider;
    }

    /**
     * Evaluate liveness for a given image.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws FacePipelineUnavailableException
     */
    public function evaluate(UploadedFile|string $imageInput, array $options = []): FaceLivenessResultDTO
    {
        $enabled = (bool) config('face.liveness.enabled', true);
        if (! $enabled) {
            // When liveness is disabled by policy, return bypass REAL
            return new FaceLivenessResultDTO(
                isLive: true,
                status: FaceLivenessStatus::REAL,
                livenessScore: 1.0,
                confidence: 1.0,
                method: 'BYPASS_DISABLED',
                rejectionReasons: [],
                details: ['enabled' => false]
            );
        }

        $result = $this->provider->evaluate($imageInput, $options);

        // Re-evaluate score against configured policy thresholds
        $thresholdReal = config('face.liveness.threshold_real');
        $thresholdSpoof = config('face.liveness.threshold_spoof');

        if ($thresholdReal !== null && $thresholdSpoof !== null) {
            $score = $result->livenessScore;
            $status = $score >= (float) $thresholdReal
                ? FaceLivenessStatus::REAL
                : ($score < (float) $thresholdSpoof ? FaceLivenessStatus::SPOOF : FaceLivenessStatus::UNCERTAIN);

            return new FaceLivenessResultDTO(
                isLive: $status === FaceLivenessStatus::REAL,
                status: $status,
                livenessScore: $result->livenessScore,
                confidence: $result->confidence,
                method: $result->method,
                rejectionReasons: $status === FaceLivenessStatus::REAL ? [] : ($result->rejectionReasons ?: ['PRESENTATION_ATTACK_SUSPECTED']),
                details: $result->details
            );
        }

        return $result;
    }
}
