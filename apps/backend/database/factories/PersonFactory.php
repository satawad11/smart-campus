<?php

namespace Database\Factories;

use App\Enums\PersonType;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // uuid is generated automatically by HasPublicUuid on create().
        return [
            'person_code' => 'P'.fake()->unique()->numberBetween(100000, 999999),
            'person_type' => PersonType::STUDENT,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'display_name' => null,
            'gender' => null,
            'primary_photo' => null,
            'status' => 'ACTIVE',
        ];
    }
}
