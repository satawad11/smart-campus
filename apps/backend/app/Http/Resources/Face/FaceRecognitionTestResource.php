<?php

namespace App\Http\Resources\Face;

use App\Services\Face\DTO\FaceRecognitionTestResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe JSON representation for Face Recognition Test (FACE-012).
 *
 * @property-read FaceRecognitionTestResult $resource
 */
class FaceRecognitionTestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof FaceRecognitionTestResult) {
            return $this->resource->toArray();
        }

        return (array) $this->resource;
    }
}
