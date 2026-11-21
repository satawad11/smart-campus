<?php

namespace App\Http\Resources;

use App\Models\AccessEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe AccessEvent representation (EVT-003/EVT-008). Perception only — no
 * access decision field lives directly on the event (Event != Decision);
 * its current/full decision history is exposed as related data instead,
 * never merged into the event's own fields. No raw embedding, no snapshot
 * bytes and no storage key: only `snapshot_available` (EVT-025); the image
 * itself is read through the audited GET /events/{uuid}/snapshot.
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
                'person_code' => $this->person->person_code,
                'person_status' => $this->person->status?->value ?? $this->person->status,
                'academic_status' => $this->person->studentProfile?->academic_status?->value ?? $this->person->studentProfile?->academic_status,
                'employment_status' => $this->person->staffProfile?->employment_status?->value ?? $this->person->staffProfile?->employment_status,
                'status' => $this->person->studentProfile?->academic_status?->value
                    ?? $this->person->studentProfile?->academic_status
                    ?? $this->person->staffProfile?->employment_status?->value
                    ?? $this->person->staffProfile?->employment_status
                    ?? $this->person->status?->value
                    ?? $this->person->status,
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
            'snapshot_available' => $this->snapshot_path !== null,
            // A REVIEW suggestion is evidence for an officer only. It is not
            // event.person and cannot drive an automatic access decision.
            'review_candidate' => ($this->metadata['classification'] ?? null) === 'REVIEW'
                ? ($this->metadata['review_candidate'] ?? null)
                : null,
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
}
