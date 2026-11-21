<?php

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Plate-read ingestion request (VEH-010, ai_node.token auth). Wire contract shared with the AI
 * node's PlateEventPayload (apps/ai-service/app/delivery/plate.py, VEH-009).
 *
 * The node sends what it read (raw text, scores). It never sends a vehicle, person, event type
 * or decision: the backend normalises the plate (contracts/thai-plates.json), looks it up and
 * picks the event type itself.
 */
class IngestPlateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by ai_node.token middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_uuid' => ['required', 'uuid'],
            'camera_uuid' => ['required', 'uuid'],
            'ai_node_uuid' => ['required', 'uuid'],
            'track_id' => ['required', 'string', 'max:255'],
            'occurred_at' => ['required', 'date'],
            'plate_text' => ['required', 'string', 'max:64'],
            'province_text' => ['sometimes', 'nullable', 'string', 'max:64'],
            'ocr_score' => ['required', 'numeric', 'min:0', 'max:1'],
            'detection_score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'frames_voted' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'model_code' => ['required', 'string', 'max:255'],
            'model_version' => ['required', 'string', 'max:100'],
            'reasons' => ['sometimes', 'array'],
            'reasons.*' => ['string', 'max:100'],

            'event_type' => ['prohibited'],
            'access_decision' => ['prohibited'],
            'decision' => ['prohibited'],
            'vehicle_id' => ['prohibited'],
            'vehicle_uuid' => ['prohibited'],
            'person_id' => ['prohibited'],
            'person_uuid' => ['prohibited'],
            'site_id' => ['prohibited'],
            'zone_id' => ['prohibited'],
            'camera_id' => ['prohibited'],
            'ai_node_id' => ['prohibited'],
        ];
    }
}
