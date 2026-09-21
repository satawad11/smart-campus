<?php

namespace App\Http\Requests\AiNode\Internal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Node heartbeat request (CAM-003, internal.token auth). Raw operational
 * telemetry only — never authorization/scope. Real numeric ranges are
 * bounded (0-100% for usage figures) so a malformed node cannot corrupt the
 * health log with nonsense values.
 */
class AiNodeHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by internal.token middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'node_code' => ['required', 'string', 'max:255'],
            'cpu_usage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'ram_usage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'gpu_usage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'gpu_memory_usage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'temperature' => ['sometimes', 'nullable', 'numeric'],
            'active_camera_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'queue_depth' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'status' => ['prohibited'],
            'site_uuid' => ['prohibited'],
        ];
    }
}
