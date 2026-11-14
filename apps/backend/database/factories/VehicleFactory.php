<?php

namespace Database\Factories;

use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $letters = ['กข', 'ขค', 'ฆฌ', 'ชม', 'นท', 'พย'];

        return [
            'plate_number' => $this->faker->numberBetween(1, 9)
                .$this->faker->randomElement($letters)
                .$this->faker->unique()->numberBetween(1000, 9999),
            'plate_province' => 'BKK',
            'vehicle_type' => VehicleType::CAR,
            'brand' => null,
            'model' => null,
            'color' => null,
            'status' => VehicleStatus::ACTIVE,
            'notes' => null,
            'created_by_user_id' => null,
        ];
    }
}
