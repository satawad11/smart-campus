<?php

namespace Database\Factories;

use App\Enums\IdDocumentType;
use App\Enums\PersonType;
use App\Models\Person;
use App\Models\VisitorProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitorProfile>
 */
class VisitorProfileFactory extends Factory
{
    protected $model = VisitorProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'person_id' => Person::factory()->state(['person_type' => PersonType::VISITOR]),
            'organization' => fake()->company(),
            'phone' => null,
            'id_document_type' => IdDocumentType::NATIONAL_ID,
            'id_document_last4' => (string) fake()->numberBetween(1000, 9999),
        ];
    }
}
