<?php

namespace Database\Factories;

use App\Enums\GateCameraRole;
use App\Models\Camera;
use App\Models\Gate;
use App\Models\GateCamera;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GateCamera>
 */
class GateCameraFactory extends Factory
{
    protected $model = GateCamera::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gate_id' => Gate::factory(),
            // Same site and zone as the gate, so the link is valid.
            'camera_id' => function (array $attributes) {
                $gate = Gate::findOrFail($attributes['gate_id']);

                return Camera::factory()->create(['site_id' => $gate->site_id, 'zone_id' => $gate->zone_id])->id;
            },
            'role' => GateCameraRole::BOTH,
        ];
    }
}
