<?php

namespace App\Http\Resources;

use App\Models\VehicleOwnershipHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person ↔ vehicle link over time (VEH-004). Shows only what an operator needs to identify
 * the person; no internal ids.
 *
 * @mixin VehicleOwnershipHistory
 */
class VehicleLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $now = now();

        return [
            'uuid' => $this->uuid,
            'relation' => $this->relation?->value,
            'effective_from' => $this->effective_from?->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
            'is_current' => $this->effective_from <= $now && ($this->effective_to === null || $this->effective_to > $now),
            'reason' => $this->reason,
            'person' => $this->whenLoaded('person', fn () => $this->person === null ? null : [
                'uuid' => $this->person->uuid,
                'person_code' => $this->person->person_code,
                'person_type' => $this->person->person_type?->value,
                'display_name' => $this->person->displayLabel(),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
