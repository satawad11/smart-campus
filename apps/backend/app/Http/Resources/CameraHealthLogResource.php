<?php

namespace App\Http\Resources;

use App\Models\CameraHealthLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe CameraHealthLog representation (CAM-013). Telemetry only — no
 * secrets, no frames/images.
 *
 * @mixin CameraHealthLog
 */
class CameraHealthLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status?->value ?? $this->status,
            'fps' => $this->fps,
            'latency_ms' => $this->latency_ms,
            'dropped_frames' => $this->dropped_frames,
            'resolution' => $this->resolution,
            'brightness_score' => $this->brightness_score,
            'blur_score' => $this->blur_score,
            'reconnect_count' => $this->reconnect_count,
            'recorded_at' => $this->recorded_at?->toISOString(),
        ];
    }
}
