<?php

namespace Database\Factories;

use App\Models\AiNode;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiNode>
 */
class AiNodeFactory extends Factory
{
    protected $model = AiNode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'node_code' => 'NODE_'.Str::upper(Str::random(6)),
            'name' => fake()->words(2, true),
            'hostname' => fake()->domainWord().'.local',
            'ip_address' => fake()->localIpv4(),
            'api_url' => 'https://'.fake()->domainWord().'.local:8000',
            'hardware_type' => 'edge-gpu',
            'cpu_model' => null,
            'gpu_name' => null,
            'gpu_memory_mb' => null,
            'max_camera' => 4,
            'status' => 'ACTIVE',
            'last_seen_at' => null,
        ];
    }
}
