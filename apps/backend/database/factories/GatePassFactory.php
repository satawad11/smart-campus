<?php

namespace Database\Factories;

use App\Enums\GateMatchResult;
use App\Models\AccessEvent;
use App\Models\Gate;
use App\Models\GatePass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default: a FACE_ONLY pass with one recognition event in the gate's site.
 *
 * @extends Factory<GatePass>
 */
class GatePassFactory extends Factory
{
    protected $model = GatePass::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Eager, so the event really is in the gate's site.
        $gate = Gate::factory()->create();
        $event = AccessEvent::factory()->create(['site_id' => $gate->site_id, 'zone_id' => $gate->zone_id]);

        return [
            'gate_id' => $gate->id,
            'face_event_id' => $event->id,
            'plate_event_id' => null,
            'person_id' => null,
            'vehicle_id' => null,
            'match_result' => GateMatchResult::FACE_ONLY,
            'occurred_at' => $event->occurred_at,
        ];
    }
}
