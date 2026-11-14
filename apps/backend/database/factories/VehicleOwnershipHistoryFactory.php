<?php

namespace Database\Factories;

use App\Enums\VehicleRelation;
use App\Models\Person;
use App\Models\Vehicle;
use App\Models\VehicleOwnershipHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleOwnershipHistory>
 */
class VehicleOwnershipHistoryFactory extends Factory
{
    protected $model = VehicleOwnershipHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'person_id' => Person::factory(),
            'relation' => VehicleRelation::OWNER,
            'effective_from' => now()->subDay(),
            'effective_to' => null,
            'reason' => null,
            'created_by_user_id' => null,
        ];
    }
}
