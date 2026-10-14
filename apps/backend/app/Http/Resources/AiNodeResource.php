<?php

namespace App\Http\Resources;

use App\Models\AiNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe AI Node representation (CAM-003). No internal ids, no per-node
 * credential (CAM-004 will add one, stored/hidden the same way camera
 * credentials are).
 *
 * @mixin AiNode
 */
class AiNodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'node_code' => $this->node_code,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'ip_address' => $this->ip_address,
            'api_url' => $this->api_url,
            'hardware_type' => $this->hardware_type,
            'software_version' => $this->software_version,
            // COM-03: reported runtime model and whether the registry has it
            // ACTIVE (the same rule recognition requests are checked against).
            'model_code' => $this->model_code,
            'model_version' => $this->model_version,
            'model_compatible' => $this->resource->isModelCompatible(),
            'applied_config_revision' => $this->applied_config_revision,
            'capabilities' => $this->capabilities,
            'cpu_model' => $this->cpu_model,
            'gpu_name' => $this->gpu_name,
            'gpu_memory_mb' => $this->gpu_memory_mb,
            'max_camera' => $this->max_camera,
            'status' => $this->status?->value ?? $this->status,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),

            'site' => $this->whenLoaded('site', fn () => $this->site === null ? null : [
                'uuid' => $this->site->uuid,
                'code' => $this->site->code,
                'name' => $this->site->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
