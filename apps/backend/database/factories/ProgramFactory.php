<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Program>
 */
class ProgramFactory extends Factory
{
    protected $model = Program::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'code' => 'PRG_'.Str::upper(Str::random(6)),
            'name' => fake()->words(2, true),
            'education_level' => 'VOC_CERT',
            'status' => 'ACTIVE',
        ];
    }
}
