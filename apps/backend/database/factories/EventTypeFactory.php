<?php

namespace Database\Factories;

use App\Models\EventType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventType>
 */
class EventTypeFactory extends Factory
{
    protected $model = EventType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'EVENT_'.Str::upper(Str::random(8)),
            'name_th' => 'ประเภทเหตุการณ์ทดสอบ',
            'name_en' => 'Test Event Type',
            'category' => 'RECOGNITION',
            'severity' => 'INFO',
            'active' => true,
        ];
    }
}
