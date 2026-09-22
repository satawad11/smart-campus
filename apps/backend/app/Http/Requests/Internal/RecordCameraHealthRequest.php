<?php

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Camera health telemetry ingestion (CAM-013, ai_node.token auth). Raw
 * observed metrics only — never a status classification: the same
 * "never trust a client-supplied status" boundary as
 * AiNodeHeartbeatRequest, so a malfunctioning or malicious node cannot
 * self-report ONLINE. CameraHealthService derives HealthStatus from these
 * numbers instead.
 */
class RecordCameraHealthRequest extends FormRequest
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
            'fps' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'latency_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'dropped_frames' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:32'],
            'brightness_score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'blur_score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'reconnect_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'recorded_at' => ['sometimes', 'nullable', 'date'],

            'status' => ['prohibited'],
            'camera_uuid' => ['prohibited'],
            'ai_node_uuid' => ['prohibited'],
        ];
    }
}
