<?php

namespace Database\Factories;

use App\Models\Camera;
use App\Models\CameraHealthLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CameraHealthLog>
 */
class CameraHealthLogFactory extends Factory
{
    protected $model = CameraHealthLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'camera_id' => Camera::factory(),
            'status' => 'ONLINE',
            'fps' => 15.0,
            'latency_ms' => 120,
            'dropped_frames' => 0,
            'resolution' => '1920x1080',
            'brightness_score' => null,
            'blur_score' => null,
            'reconnect_count' => 0,
            'recorded_at' => now(),
        ];
    }
}
