<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // uuid is generated automatically by HasPublicUuid on create().
        return [
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => false,
            'must_change_password' => false,
        ];
    }

    /**
     * A locked account.
     */
    public function locked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::LOCKED,
            'locked_until' => now()->addHour(),
        ]);
    }
}
