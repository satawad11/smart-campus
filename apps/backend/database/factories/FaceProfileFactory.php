<?php

namespace Database\Factories;

use App\Enums\FaceProfileStatus;
use App\Models\FaceProfile;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FaceProfile>
 */
class FaceProfileFactory extends Factory
{
    protected $model = FaceProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // uuid is generated automatically by HasPublicUuid on create().
        return [
            'person_id' => Person::factory(),
            'recognition_model_id' => null,
            'embedding_dimension' => 512,
            'quality_score' => 0.88,
            'status' => FaceProfileStatus::ACTIVE,
            'enrolled_by_user_id' => null,
            'enrolled_at' => now(),
            'revoked_at' => null,
            'revocation_reason' => null,
        ];
    }
}
