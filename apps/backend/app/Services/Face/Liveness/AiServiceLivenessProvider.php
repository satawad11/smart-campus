<?php

namespace App\Services\Face\Liveness;

use App\Enums\FaceLivenessStatus;
use App\Exceptions\Face\FacePipelineUnavailableException;
use App\Exceptions\Face\InvalidImageException;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * AI Perception Service Liveness Provider (FACE-015, docs/02-api-contract.md §8).
 * Communicates with FastAPI endpoint POST /internal/v1/face/liveness.
 */
class AiServiceLivenessProvider implements FaceLivenessProviderInterface
{
    public function __construct(
        protected string $baseUrl,
        protected int $timeoutSeconds = 5
    ) {}

    public function evaluate(UploadedFile|string $imageBytes, array $options = []): FaceLivenessResultDTO
    {
        if ($imageBytes instanceof UploadedFile) {
            $rawBytes = (string) $imageBytes->get();
            $filename = $imageBytes->getClientOriginalName() ?: 'face.jpg';
        } else {
            $rawBytes = $imageBytes;
            $filename = 'face.jpg';
        }

        if (empty($rawBytes)) {
            throw new InvalidImageException('Liveness image payload cannot be empty.');
        }

        $url = rtrim($this->baseUrl, '/').'/internal/v1/face/liveness';

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->attach('file', $rawBytes, $filename)
                ->post($url);
        } catch (ConnectionException $e) {
            throw new FacePipelineUnavailableException(
                "Face liveness perception service connection failed: {$e->getMessage()}",
                previous: $e
            );
        } catch (Exception $e) {
            throw new FacePipelineUnavailableException(
                "Face liveness perception service request failed: {$e->getMessage()}",
                previous: $e
            );
        }

        if ($response->status() === 503 || $response->status() === 504) {
            throw new FacePipelineUnavailableException(
                'Face liveness perception service is temporarily unavailable.'
            );
        }

        $data = $response->json();

        if ($response->status() === 422 || ! ($data['success'] ?? false)) {
            $message = $data['message'] ?? 'Liveness check rejected the image.';

            return new FaceLivenessResultDTO(
                isLive: false,
                status: FaceLivenessStatus::SPOOF,
                livenessScore: 0.0,
                confidence: 1.0,
                method: 'AI_SERVICE',
                rejectionReasons: (array) ($data['rejection_reasons'] ?? ['IMAGE_REJECTED']),
                details: (array) ($data['details'] ?? [])
            );
        }

        if (! $response->successful()) {
            throw new FacePipelineUnavailableException(
                "Face liveness service returned error status: {$response->status()}"
            );
        }

        return FaceLivenessResultDTO::fromArray($data);
    }
}
