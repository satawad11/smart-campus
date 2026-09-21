<?php

namespace App\Http\Requests\AiNode;

use App\Enums\DeviceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin AI-node provisioning request (CAM-003). Establishes the node's
 * identity/scope; the physical node later confirms itself via the internal
 * check-in/heartbeat endpoints (CAM-004 credentials), never by self-creating.
 */
class StoreAiNodeRequest extends FormRequest
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
            'site_uuid' => ['nullable', 'string', Rule::exists('sites', 'uuid')],
            'node_code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'api_url' => ['nullable', 'string', 'max:2048'],
            'hardware_type' => ['nullable', 'string', 'max:255'],
            'cpu_model' => ['nullable', 'string', 'max:255'],
            'gpu_name' => ['nullable', 'string', 'max:255'],
            'gpu_memory_mb' => ['nullable', 'integer', 'min:0'],
            'max_camera' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'status' => ['sometimes', Rule::enum(DeviceStatus::class)],

            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'site_id' => ['prohibited'],
            'software_version' => ['prohibited'],
            'capabilities' => ['prohibited'],
            'last_seen_at' => ['prohibited'],
        ];
    }
}
