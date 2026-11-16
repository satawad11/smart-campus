<?php

namespace Database\Factories;

use App\Enums\GuardianRelation;
use App\Enums\PersonType;
use App\Models\GuardianLink;
use App\Models\Person;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuardianLink>
 */
class GuardianLinkFactory extends Factory
{
    protected $model = GuardianLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guardian_person_id' => Person::factory()->state(['person_type' => PersonType::PARENT]),
            'student_person_id' => fn () => StudentProfile::factory()->create()->person_id,
            'relation' => GuardianRelation::GUARDIAN,
        ];
    }
}
