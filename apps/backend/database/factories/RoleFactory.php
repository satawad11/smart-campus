<?php

namespace Database\Factories;

use App\Enums\RoleStatus;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // uuid is generated automatically by HasPublicUuid on create().
        return [
            'code' => 'ROLE_'.Str::upper(Str::random(8)),
            'name' => fake()->jobTitle(),
            'description' => null,
            'system_role' => false,
            'status' => RoleStatus::ACTIVE,
        ];
    }
}
