<?php

namespace App\Http\Requests\Camera;

use App\Enums\DeviceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create-Camera request (CAM-002). Related entities addressed by public
 * UUID, resolved server-side. Credentials are plaintext in the request body
 * (over TLS) but are encrypted before persistence and never echoed back.
 */
class StoreCameraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (camera.create)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Optional: when omitted the server picks (or provisions) the
            // default site/zone, and generates the next CAM-#### code.
            'site_uuid' => ['bail', 'nullable', 'string', 'uuid', Rule::exists('sites', 'uuid')],
            'zone_uuid' => ['bail', 'nullable', 'string', 'uuid', Rule::exists('zones', 'uuid')],
            'ai_node_uuid' => ['nullable', 'string', Rule::exists('ai_nodes', 'uuid')],
            'camera_code' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'camera_type' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'stream_protocol' => ['nullable', 'string', 'max:255'],
            'rtsp_url' => ['nullable', 'string', 'max:2048'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'onvif_url' => ['nullable', 'string', 'max:2048'],
            'face_detection_enabled' => ['sometimes', 'boolean'],
            'face_recognition_enabled' => ['sometimes', 'boolean'],
            'liveness_enabled' => ['sometimes', 'boolean'],
            'snapshot_enabled' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(DeviceStatus::class)],

            // Internal identifiers may never be client-supplied.
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'site_id' => ['prohibited'],
            'zone_id' => ['prohibited'],
            'ai_node_id' => ['prohibited'],
            'rtsp_url_encrypted' => ['prohibited'],
            'username_encrypted' => ['prohibited'],
            'password_encrypted' => ['prohibited'],
        ];
    }
}
