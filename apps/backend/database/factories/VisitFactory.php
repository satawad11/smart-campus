<?php

namespace Database\Factories;

use App\Enums\PersonType;
use App\Enums\VisitStatus;
use App\Models\Person;
use App\Models\Site;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    protected $model = Visit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visitor_person_id' => Person::factory()->state(['person_type' => PersonType::VISITOR]),
            'host_person_id' => null,
            'purpose' => 'ติดต่อราชการ',
            'site_id' => Site::factory(),
            'zone_id' => null,
            'valid_from' => now()->subHour(),
            'valid_until' => now()->addHours(4),
            'status' => VisitStatus::SCHEDULED,
        ];
    }
}
