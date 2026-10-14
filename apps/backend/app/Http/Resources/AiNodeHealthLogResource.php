<?php

namespace App\Http\Resources;

use App\Models\AiNodeHealthLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe AiNodeHealthLog representation (CAM-014). Telemetry only — no
 * secrets, no biometric data.
 *
 * @mixin AiNodeHealthLog
 */
class AiNodeHealthLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status?->value ?? $this->status,
            'cpu_usage' => $this->cpu_usage,
            'ram_usage' => $this->ram_usage,
            'gpu_usage' => $this->gpu_usage,
            'gpu_memory_usage' => $this->gpu_memory_usage,
            'temperature' => $this->temperature,
            'active_camera_count' => $this->active_camera_count,
            'queue_depth' => $this->queue_depth,
            'disk_usage' => $this->disk_usage,
            'recorded_at' => $this->recorded_at?->toISOString(),
        ];
    }
}
