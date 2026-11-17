<?php

namespace App\Http\Resources;

use App\Models\AccessEvent;
use App\Support\Vehicles\ThaiProvince;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe AccessEvent representation (EVT-003/EVT-008). Perception only — no
 * access decision field lives directly on the event (Event != Decision);
 * its current/full decision history is exposed as related data instead,
 * never merged into the event's own fields. No raw embedding, no snapshot
 * bytes.
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
            'person' => $this->person === null ? null : [
                'uuid' => $this->person->uuid,
                'display_name' => $this->person->display_name,
                // Additive: name with first/last fallback and the person's group.
                'name' => $this->person->displayLabel(),
                'person_type' => $this->person->person_type?->value,
            ],
            'site' => $this->site === null ? null : [
                'uuid' => $this->site->uuid,
                'name' => $this->site->name,
            ],
            'zone' => $this->zone === null ? null : [
                'uuid' => $this->zone->uuid,
                'name' => $this->zone->name,
            ],
            'camera' => $this->camera === null ? null : [
                'uuid' => $this->camera->uuid,
                'name' => $this->camera->name,
            ],
            'ai_node_uuid' => $this->aiNode?->uuid,
            'event_type' => $this->event_type,
            'occurred_at' => $this->occurred_at?->toISOString(),
            'received_at' => $this->received_at?->toISOString(),
            'face_score' => $this->face_score,
            'liveness_score' => $this->liveness_score,
            'quality_score' => $this->quality_score,
            'track_id' => $this->track_id,
            // VEH-012: what was read from the plate (plate events only). A plate event never
            // carries a person or a decision.
            'plate' => str_starts_with((string) $this->event_type, 'PLATE_') ? $this->plateBlock() : null,
            // Current (most recent) decision only — a compact summary for
            // list views. The full append-only history is `decisions`,
            // only present when explicitly eager-loaded (show()).
            'current_decision' => $this->whenLoaded(
                'latestDecision',
                fn () => $this->latestDecision === null ? null : new AccessDecisionResource($this->latestDecision),
            ),
            'decisions' => AccessDecisionResource::collection($this->whenLoaded('decisions')),
        ];
    }

    /** @return array<string, mixed> */
    private function plateBlock(): array
    {
        $plate = $this->metadata['plate'] ?? [];

        return [
            'plate_number' => $plate['plate_number'] ?? null,
            'plate_display' => $plate['plate_display'] ?? null,
            'plate_province' => $plate['plate_province'] ?? null,
            'plate_province_name' => isset($plate['plate_province']) ? ThaiProvince::name($plate['plate_province']) : null,
            'raw_text' => $plate['raw_text'] ?? null,
            'ocr_score' => $plate['ocr_score'] ?? null,
            'vehicle_uuid' => $plate['vehicle_uuid'] ?? null,
            'vehicle_status' => $plate['vehicle_status'] ?? null,
        ];
    }
}
