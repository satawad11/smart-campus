<?php

namespace App\Http\Resources\Face;

use App\Models\FaceDuplicateReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe JSON representation for FaceDuplicateReview model (FACE-011).
 *
 * @mixin FaceDuplicateReview
 */
class FaceDuplicateReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'source_face_profile_uuid' => $this->sourceProfile?->uuid,
            'source_person_uuid' => $this->sourceProfile?->person?->uuid,
            'candidate_face_profile_uuid' => $this->candidateProfile?->uuid,
            'candidate_person_uuid' => $this->candidateProfile?->person?->uuid,
            'model' => $this->recognitionModel ? [
                'code' => $this->recognitionModel->code,
                'version' => $this->recognitionModel->version,
                'type' => $this->recognitionModel->model_type->value,
            ] : null,
            'distance' => $this->distance,
            'similarity' => $this->similarity,
            'review_status' => $this->review_status->value,
            'reviewed_by_user_uuid' => $this->reviewer?->uuid,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
