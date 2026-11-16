<?php

namespace Database\Factories;

use App\Enums\PersonType;
use App\Enums\StaffEmploymentStatus;
use App\Models\Person;
use App\Models\StaffProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffProfile>
 */
class StaffProfileFactory extends Factory
{
    protected $model = StaffProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // A staff profile's person is a TEACHER or STAFF member.
            'person_id' => Person::factory()->state(['person_type' => PersonType::STAFF]),
            'staff_code' => 'T'.fake()->unique()->numberBetween(10000, 99999),
            'position' => 'เจ้าหน้าที่',
            'department_id' => null,
            'employment_status' => StaffEmploymentStatus::ACTIVE,
        ];
    }
}
