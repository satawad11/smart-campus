<?php

namespace App\Http\Resources;

use App\Models\GatePass;
use App\Support\Gates\GatePassSummary;
use App\Support\Vehicles\ThaiProvince;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the gate pass history (GATE-008): the same gate block as GATE-007, plus who and which
 * registered vehicle the pass was linked to, and its current decision. UUIDs only. Decision record
 * only; nothing here opens a barrier (ACR-VEH-01 D5).
 *
 * @mixin GatePass
 */
class GatePassResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gate = $this->gate;
        $decision = $this->latestDecision;

        return [
            ...GatePassSummary::of($this->resource),
            'site' => $gate?->site === null ? null : ['uuid' => $gate->site->uuid, 'name' => $gate->site->name],
            'person' => $this->person === null ? null : [
                'uuid' => $this->person->uuid,
                'name' => $this->person->displayLabel(),
                'person_code' => $this->person->person_code,
                'person_type' => $this->person->person_type?->value,
            ],
            'vehicle' => $this->vehicle === null ? null : [
                'uuid' => $this->vehicle->uuid,
                'plate_number' => $this->vehicle->plate_number,
                'plate_province' => $this->vehicle->plate_province,
                'plate_province_name' => $this->vehicle->plate_province ? ThaiProvince::name($this->vehicle->plate_province) : null,
                'status' => $this->vehicle->status?->value ?? $this->vehicle->status,
            ],
            'decision' => $decision === null ? null : [
                'uuid' => $decision->uuid,
                'decision' => $decision->decision?->value ?? $decision->decision,
                'reason_code' => $decision->reason_code,
                'decision_source' => $decision->decision_source?->value ?? $decision->decision_source,
                'created_at' => $decision->created_at?->toISOString(),
            ],
        ];
    }
}
