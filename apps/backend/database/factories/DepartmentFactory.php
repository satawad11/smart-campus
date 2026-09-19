<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'DEP_'.Str::upper(Str::random(6)),
            'name' => fake()->words(2, true),
            'description' => null,
            'status' => 'ACTIVE',
        ];
    }
}
