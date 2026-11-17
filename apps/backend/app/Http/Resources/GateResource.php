<?php

namespace App\Http\Resources;

use App\Models\Gate;
use App\Models\GateCamera;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Smart Gate representation (GATE-002). Public identity is uuid; internal ids are never exposed.
 * Each camera shows its role and its own face/plate switches, so the UI can warn when a role
 * does not match what the camera is set to read.
 *
 * @mixin Gate
 */
class GateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'direction' => $this->direction?->value,
            'correlation_window_seconds' => $this->correlation_window_seconds,
            'mode' => $this->mode?->value,
            'status' => $this->status?->value,
            'notes' => $this->notes,
            'site' => $this->whenLoaded('site', fn () => $this->site ? ['uuid' => $this->site->uuid, 'name' => $this->site->name] : null),
            'zone' => $this->whenLoaded('zone', fn () => $this->zone ? ['uuid' => $this->zone->uuid, 'name' => $this->zone->name] : null),
            'cameras' => $this->whenLoaded('gateCameras', fn () => $this->gateCameras
                ->filter(fn (GateCamera $link) => $link->camera !== null)
                ->map(fn (GateCamera $link) => [
                    'uuid' => $link->camera->uuid,
                    'name' => $link->camera->name,
                    'camera_code' => $link->camera->camera_code,
                    'role' => $link->role->value,
                    'status' => $link->camera->status?->value,
                    'face_recognition_enabled' => (bool) $link->camera->face_recognition_enabled,
                    'plate_recognition_enabled' => (bool) $link->camera->plate_recognition_enabled,
                ])->values()->all()),
            'camera_count' => $this->whenCounted('gateCameras'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
