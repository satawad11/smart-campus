<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Classroom>
 */
class ClassroomFactory extends Factory
{
    protected $model = Classroom::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'program_id' => null,
            'code' => 'CLS_'.Str::upper(Str::random(6)),
            'name' => fake()->words(2, true),
            'academic_year' => '2567',
            'year_level' => 1,
            'status' => 'ACTIVE',
        ];
    }
}
