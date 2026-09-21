<?php

namespace App\Services\Face;

use App\Exceptions\Face\FaceEnrollmentException;
use App\Exceptions\Face\FacePipelineUnavailableException;
use App\Exceptions\Face\FaceQualityRejectedException;
use App\Exceptions\Face\InvalidImageException;
use App\Exceptions\Face\MultipleFacesDetectedException;
use App\Exceptions\Face\NoFaceDetectedException;
use App\Services\Face\DTO\FacePipelineResult;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Internal AI Perception Service Client (FACE-009).
 *
 * Communicates with the FastAPI AI service over the internal network
 * to execute detection, alignment, quality evaluation, and embedding generation.
 */
class FacePipelineClient
{
    public function __construct(
        protected ?string $baseUrl = null,
        protected int $timeoutSeconds = 10
    ) {
        $this->baseUrl = $baseUrl ?? (string) config('services.ai.url', 'http://ai-service:8001');
    }

    /**
     * Send enrollment image bytes to AI perception pipeline.
     *
     * @param  string  $imageBytes  Raw binary image bytes
     * @param  string  $mimeType  MIME type of the uploaded image
     * @param  string  $pose  Target pose (default FRONT)
     * @param  string  $source  Sample source (default UPLOAD)
     *
     * @throws NoFaceDetectedException
     * @throws MultipleFacesDetectedException
     * @throws FaceQualityRejectedException
     * @throws InvalidImageException
     * @throws FacePipelineUnavailableException
     * @throws FaceEnrollmentException
     */
    public function processEnrollment(
        string $imageBytes,
        string $mimeType = 'image/jpeg',
        string $pose = 'FRONT',
        string $source = 'UPLOAD'
    ): FacePipelineResult {
        $url = rtrim($this->baseUrl, '/').'/internal/v1/face/enroll';

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->attach('file', $imageBytes, 'enrollment_sample.jpg', ['Content-Type' => $mimeType])
                ->post($url, [
                    'pose' => $pose,
                    'source' => $source,
                ]);
        } catch (ConnectionException $e) {
            throw new FacePipelineUnavailableException(
                "Failed to connect to AI perception service at [{$url}]: {$e->getMessage()}"
            );
        } catch (Exception $e) {
            throw new FacePipelineUnavailableException(
                "AI perception service request error: {$e->getMessage()}"
            );
        }

        if ($response->successful()) {
            return FacePipelineResult::fromArray((array) $response->json());
        }

        $statusCode = $response->status();
        $body = (array) $response->json();
        $detail = isset($body['detail']) && is_array($body['detail']) ? $body['detail'] : $body;

        $errorCode = (string) ($detail['error_code'] ?? 'AI_PIPELINE_ERROR');
        $message = (string) ($detail['message'] ?? 'Face perception pipeline error.');

        if ($statusCode === 422) {
            if ($errorCode === 'NO_FACE') {
                throw new NoFaceDetectedException($message);
            }

            if ($errorCode === 'MULTIPLE_FACES') {
                $faceCount = (int) ($detail['face_count'] ?? 2);
                throw new MultipleFacesDetectedException($faceCount, $message);
            }

            if ($errorCode === 'FACE_QUALITY_REJECTED') {
                $reasons = array_values(array_map('strval', (array) ($detail['rejection_reasons'] ?? [])));
                $quality = (array) ($detail['quality'] ?? []);
                throw new FaceQualityRejectedException($reasons, $quality, $message);
            }

            if ($errorCode === 'INVALID_IMAGE') {
                throw new InvalidImageException($message);
            }

            throw new FaceEnrollmentException($message, $errorCode, 422, $detail);
        }

        if ($statusCode >= 500) {
            throw new FacePipelineUnavailableException(
                "AI perception service returned HTTP {$statusCode}: {$message}"
            );
        }

        throw new FaceEnrollmentException($message, $errorCode, $statusCode, $detail);
    }
}
