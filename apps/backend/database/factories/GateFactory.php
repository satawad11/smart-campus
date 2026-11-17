<?php

namespace Database\Factories;

use App\Enums\GateDirection;
use App\Enums\GateMode;
use App\Models\Gate;
use App\Models\Site;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Gate>
 */
class GateFactory extends Factory
{
    protected $model = Gate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Eager, so the zone really belongs to the site (see CameraFactory).
        $site = Site::factory()->create();

        return [
            'site_id' => $site->id,
            'zone_id' => Zone::factory()->for($site)->create()->id,
            'code' => 'GATE_'.Str::upper(Str::random(6)),
            'name' => fake()->words(2, true),
            'direction' => GateDirection::BOTH,
            'correlation_window_seconds' => 10,
            'mode' => GateMode::FACE_PRIMARY,
            'status' => 'ACTIVE',
            'notes' => null,
            'created_by_user_id' => null,
        ];
    }
}
