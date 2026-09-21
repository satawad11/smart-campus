<?php

namespace App\Http\Resources\Face;

use App\Models\FaceProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FaceProfile
 */
class FaceProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primarySample = $this->samples->sortByDesc('created_at')->first();

        return [
            'face_profile_uuid' => $this->uuid,
            'person_uuid' => $this->person?->uuid,
            'status' => $this->status->value,
            'quality_score' => $this->quality_score,
            'enrolled_at' => $this->enrolled_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revocation_reason' => $this->revocation_reason,
            'model' => $this->recognitionModel ? [
                'code' => $this->recognitionModel->code,
                'version' => $this->recognitionModel->version,
            ] : null,
            'sample' => $primarySample ? [
                'uuid' => $primarySample->uuid,
                'pose' => $primarySample->pose->value,
                'source' => $primarySample->source->value,
                'quality_score' => $primarySample->quality_score,
                'blur_score' => $primarySample->blur_score,
                'brightness_score' => $primarySample->brightness_score,
                'pose_score' => $primarySample->pose_score,
                'captured_at' => $primarySample->captured_at?->toIso8601String(),
            ] : null,
            'samples_count' => $this->relationLoaded('samples') ? $this->samples->count() : $this->samples()->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
