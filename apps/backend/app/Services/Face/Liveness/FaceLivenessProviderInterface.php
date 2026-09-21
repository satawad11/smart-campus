<?php

namespace App\Services\Face\Liveness;

use Illuminate\Http\UploadedFile;

/**
 * Provider interface for presentation attack detection / liveness analysis (FACE-015).
 */
interface FaceLivenessProviderInterface
{
    /**
     * Evaluate an image payload for liveness and spoof detection.
     *
     * @param  array<string, mixed>  $options
     */
    public function evaluate(UploadedFile|string $imageBytes, array $options = []): FaceLivenessResultDTO;
}
