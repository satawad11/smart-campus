<?php

namespace Database\Factories;

use App\Models\EventDailySummary;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventDailySummary>
 */
class EventDailySummaryFactory extends Factory
{
    protected $model = EventDailySummary::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'zone_id' => null,
            'camera_id' => null,
            'department_id' => null,
            'summary_date' => now()->toDateString(),
            'event_type' => 'FACE_RECOGNIZED',
            'decision' => 'ALLOW',
            'event_count' => 1,
            'unique_person_count' => 1,
            'refreshed_at' => now(),
        ];
    }
}
