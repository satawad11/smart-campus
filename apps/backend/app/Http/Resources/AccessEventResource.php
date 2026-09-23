<?php

namespace App\Http\Resources;

use App\Models\AccessEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe AccessEvent representation (EVT-003). Perception only — no access
 * decision field exists here (Event != Decision); no raw embedding, no
 * snapshot bytes.
 *
 * @mixin AccessEvent
 */
class AccessEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'person_uuid' => $this->person?->uuid,
            'site_uuid' => $this->site?->uuid,
            'zone_uuid' => $this->zone?->uuid,
            'camera_uuid' => $this->camera?->uuid,
            'ai_node_uuid' => $this->aiNode?->uuid,
            'event_type' => $this->event_type,
            'occurred_at' => $this->occurred_at?->toISOString(),
            'received_at' => $this->received_at?->toISOString(),
            'face_score' => $this->face_score,
            'liveness_score' => $this->liveness_score,
            'quality_score' => $this->quality_score,
            'track_id' => $this->track_id,
        ];
    }
}
