<?php

namespace App\Http\Resources;

use App\Models\Camera;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe Camera representation (CAM-002).
 *
 * Never exposes rtsp_url_encrypted/username_encrypted/password_encrypted
 * (also $hidden on the model itself — belt and suspenders). Instead exposes
 * boolean has_rtsp_url/has_credentials so a UI can show "configured" state
 * without ever seeing the value, mirroring the existing mfa_enabled pattern.
 *
 * @mixin Camera
 */
class CameraResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'camera_code' => $this->camera_code,
            'name' => $this->name,
            'camera_type' => $this->camera_type,
            'ip_address' => $this->ip_address,
            'stream_protocol' => $this->stream_protocol,
            'onvif_url' => $this->onvif_url,
            'has_rtsp_url' => $this->rtsp_url_encrypted !== null,
            'has_credentials' => $this->username_encrypted !== null,
            'face_detection_enabled' => $this->face_detection_enabled,
            'face_recognition_enabled' => $this->face_recognition_enabled,
            'liveness_enabled' => $this->liveness_enabled,
            'snapshot_enabled' => $this->snapshot_enabled,
            'roi' => $this->roi_polygon,
            'status' => $this->status?->value ?? $this->status,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),

            'site' => $this->whenLoaded('site', fn () => [
                'uuid' => $this->site->uuid,
                'code' => $this->site->code,
                'name' => $this->site->name,
            ]),

            'zone' => $this->whenLoaded('zone', fn () => [
                'uuid' => $this->zone->uuid,
                'code' => $this->zone->code,
                'name' => $this->zone->name,
            ]),

            'ai_node' => $this->whenLoaded('aiNode', fn () => $this->aiNode === null ? null : [
                'uuid' => $this->aiNode->uuid,
                'node_code' => $this->aiNode->node_code,
                'name' => $this->aiNode->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
