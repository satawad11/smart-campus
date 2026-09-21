<?php

namespace App\Http\Resources\Face;

use App\Services\Face\DTO\FaceDuplicateReviewResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe JSON representation for Face Duplicate Review Result query (FACE-011).
 *
 * @property-read FaceDuplicateReviewResult $resource
 */
class FaceDuplicateReviewResultResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof FaceDuplicateReviewResult) {
            return $this->resource->toArray();
        }

        return (array) $this->resource;
    }
}
