<?php

namespace App\Support\Gates;

use App\Models\GatePass;
use App\Support\Vehicles\ThaiProvince;

/**
 * The Smart Gate block shown with a gate decision (GATE-007): in the events API, the realtime push
 * and the notification. One shape everywhere, built from the pass only. Never a barrier command.
 */
final class GatePassSummary
{
    /** @return array<string, mixed> */
    public static function of(GatePass $pass): array
    {
        $gate = $pass->gate;
        $plate = $pass->plateEvent?->metadata['plate'] ?? null;

        return [
            'uuid' => $pass->uuid,
            'match_result' => $pass->match_result->value,
            'occurred_at' => $pass->occurred_at?->toISOString(),
            'gate' => $gate === null ? null : [
                'uuid' => $gate->uuid,
                'code' => $gate->code,
                'name' => $gate->name,
            ],
            'face_event_uuid' => $pass->faceEvent?->uuid,
            'plate_event_uuid' => $pass->plateEvent?->uuid,
            // What the plate camera read. Gone once the plate read is redacted by retention.
            'plate' => $plate === null ? null : [
                'plate_number' => $plate['plate_number'] ?? null,
                'plate_display' => $plate['plate_display'] ?? null,
                'plate_province' => $plate['plate_province'] ?? null,
                'plate_province_name' => isset($plate['plate_province']) ? ThaiProvince::name($plate['plate_province']) : null,
                'vehicle_uuid' => $plate['vehicle_uuid'] ?? null,
            ],
        ];
    }
}
