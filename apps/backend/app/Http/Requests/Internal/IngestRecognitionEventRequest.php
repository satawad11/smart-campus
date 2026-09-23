<?php

namespace App\Http\Requests\Internal;

use App\Enums\FaceLivenessStatus;
use App\Enums\FaceRecognitionClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recognition-event ingestion request (EVT-003, ai_node.token auth).
 * Matches CAM-011's RecognitionEventPayload wire contract exactly
 * (apps/ai-service/app/delivery/client.py).
 *
 * ai_node_uuid is present (the AI node's delivery client always sends it)
 * but is never trusted as identity on its own — RecognitionEventIngestion
 * Service cross-checks it against the bearer-token-resolved node and
 * rejects a mismatch, the same "identity from auth, never from body"
 * invariant as every other /internal/v1 endpoint (CAM-004/013/015).
 *
 * No decision/access_decision field exists here at all: that is
 * exclusively Module 05's own server-side concern (the policy matcher,
 * EVT-006), never something the AI node computes or supplies.
 */
class IngestRecognitionEventRequest extends FormRequest
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
            'classification' => ['required', Rule::enum(FaceRecognitionClassification::class)],
            'candidate_person_uuid' => ['sometimes', 'nullable', 'uuid'],
            'similarity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'distance' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'model_code' => ['required', 'string', 'max:255'],
            'model_version' => ['required', 'string', 'max:100'],
            'quality_score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'liveness_status' => ['sometimes', 'nullable', Rule::enum(FaceLivenessStatus::class)],
            'rejected_locally' => ['required', 'boolean'],
            'reasons' => ['sometimes', 'array'],
            'reasons.*' => ['string'],

            'access_decision' => ['prohibited'],
            'decision' => ['prohibited'],
            'person_id' => ['prohibited'],
            'site_id' => ['prohibited'],
            'zone_id' => ['prohibited'],
            'camera_id' => ['prohibited'],
            'ai_node_id' => ['prohibited'],
        ];
    }
}
