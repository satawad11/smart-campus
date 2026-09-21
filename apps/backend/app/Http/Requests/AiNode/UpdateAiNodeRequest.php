<?php

namespace App\Http\Requests\AiNode;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin AI-node update request (CAM-003). node_code is immutable; status
 * uses the dedicated status operation; software_version/capabilities are
 * node-self-reported only (never admin-writable) to avoid two conflicting
 * sources of truth for what the node itself reports about its build.
 */
class UpdateAiNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (ai_node.manage)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'site_uuid' => ['sometimes', 'nullable', 'string', Rule::exists('sites', 'uuid')],
            'name' => ['sometimes', 'string', 'max:255'],
            'hostname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ip_address' => ['sometimes', 'nullable', 'ip'],
            'api_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'hardware_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cpu_model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gpu_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gpu_memory_mb' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_camera' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],

            'status' => ['prohibited'],
            'node_code' => ['prohibited'],
            'software_version' => ['prohibited'],
            'capabilities' => ['prohibited'],
            'last_seen_at' => ['prohibited'],
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'site_id' => ['prohibited'],
        ];
    }
}
