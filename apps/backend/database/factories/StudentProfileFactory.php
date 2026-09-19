<?php

namespace Database\Factories;

use App\Enums\PersonType;
use App\Enums\StudentAcademicStatus;
use App\Models\Department;
use App\Models\Person;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentProfile>
 */
class StudentProfileFactory extends Factory
{
    protected $model = StudentProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // A student profile's person is a STUDENT (invariant for STD-006).
            'person_id' => Person::factory()->state(['person_type' => PersonType::STUDENT]),
            'student_code' => (string) fake()->unique()->numberBetween(6400001, 6499999),
            'department_id' => Department::factory(),
            'program_id' => null,
            'classroom_id' => null,
            'education_level' => 'VOC_CERT',
            'year_level' => 1,
            'academic_status' => StudentAcademicStatus::ACTIVE,
        ];
    }
}
