<?php

namespace Database\Factories;

use App\Models\AiNode;
use App\Models\AiNodeHealthLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiNodeHealthLog>
 */
class AiNodeHealthLogFactory extends Factory
{
    protected $model = AiNodeHealthLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_node_id' => AiNode::factory(),
            'cpu_usage' => 25.5,
            'ram_usage' => 40.0,
            'gpu_usage' => null,
            'gpu_memory_usage' => null,
            'temperature' => null,
            'active_camera_count' => 1,
            'queue_depth' => 0,
            'status' => 'ONLINE',
            'recorded_at' => now(),
        ];
    }
}
