<?php

namespace App\Http\Requests\Camera;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Generic Camera update request (CAM-002).
 *
 * Credential fields (rtsp_url/username/password) use the three-state
 * contract: key absent = unchanged; key present with a value = replace
 * (re-encrypted); key present as null = explicitly cleared. camera_code,
 * site, and zone are immutable here (reassignment semantics undefined —
 * deferred, matching the STD-006/007 precedent); ai_node_uuid may be
 * reassigned or cleared. Status changes use the dedicated status operation.
 */
class UpdateCameraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (camera.update)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'camera_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ip_address' => ['sometimes', 'nullable', 'ip'],
            'stream_protocol' => ['sometimes', 'nullable', 'string', 'max:255'],
            'rtsp_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'onvif_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            // 'bail' + 'uuid' before 'exists': PostgreSQL's uuid column rejects
            // a malformed string at the SQL level (invalid input syntax), so
            // format must be checked first or an invalid value 500s instead
            // of failing validation cleanly. See ReviewEventRequest.
            'ai_node_uuid' => ['bail', 'sometimes', 'nullable', 'string', 'uuid', 'exists:ai_nodes,uuid'],
            'face_detection_enabled' => ['sometimes', 'boolean'],
            'face_recognition_enabled' => ['sometimes', 'boolean'],
            'liveness_enabled' => ['sometimes', 'boolean'],
            'snapshot_enabled' => ['sometimes', 'boolean'],
            // VEH-005: turning it on also needs feature.alpr (checked in CameraService).
            'plate_recognition_enabled' => ['sometimes', 'boolean'],

            // Dedicated status operation, immutable keys, internal ids.
            'status' => ['prohibited'],
            'camera_code' => ['prohibited'],
            'site_uuid' => ['prohibited'],
            'zone_uuid' => ['prohibited'],
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
