<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $module = fake()->randomElement(['user', 'role', 'student', 'report', 'monitor']);

        return [
            'code' => $module.'.'.Str::lower(Str::random(6)),
            'module' => $module,
            'name' => fake()->words(3, true),
            'description' => null,
        ];
    }
}
