<?php

namespace Database\Factories;

use App\Models\AccessEvent;
use App\Models\EventType;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessEvent>
 */
class AccessEventFactory extends Factory
{
    protected $model = AccessEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventType = EventType::firstOrCreate(
            ['code' => 'FACE_RECOGNIZED'],
            EventType::factory()->raw(['code' => 'FACE_RECOGNIZED']),
        );

        return [
            'person_id' => null,
            'site_id' => Site::factory(),
            'zone_id' => null,
            'camera_id' => null,
            'ai_node_id' => null,
            'event_type' => $eventType->code,
            'occurred_at' => now(),
            'received_at' => now(),
            'face_score' => 0.95,
            'liveness_score' => 0.9,
            'quality_score' => 0.9,
            'track_id' => (string) $this->faker->uuid(),
            'snapshot_path' => null,
            'metadata' => null,
        ];
    }
}
