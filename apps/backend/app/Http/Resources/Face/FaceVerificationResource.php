<?php

namespace App\Http\Resources\Face;

use App\Services\Face\DTO\FaceVerificationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe JSON representation for Face Enrollment Verification (FACE-010).
 *
 * @property-read FaceVerificationResult $resource
 */
class FaceVerificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof FaceVerificationResult) {
            return $this->resource->toArray();
        }

        return (array) $this->resource;
    }
}
