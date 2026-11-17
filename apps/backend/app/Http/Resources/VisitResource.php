<?php

namespace App\Http\Resources;

use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Safe visit representation (PPL-006). */
class VisitResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Visit $visit */
        $visit = $this->resource;

        return [
            'uuid' => $visit->uuid,
            'visitor' => $visit->visitor ? [
                'uuid' => $visit->visitor->uuid,
                'name' => $visit->visitor->displayLabel(),
                'person_type' => $visit->visitor->person_type?->value ?? $visit->visitor->person_type,
                'person_code' => $visit->visitor->person_code,
            ] : null,
            'host' => $visit->host ? [
                'uuid' => $visit->host->uuid,
                'name' => $visit->host->displayLabel(),
            ] : null,
            'purpose' => $visit->purpose,
            'site' => $visit->site ? ['uuid' => $visit->site->uuid, 'code' => $visit->site->code, 'name' => $visit->site->name] : null,
            'zone' => $visit->zone ? ['uuid' => $visit->zone->uuid, 'code' => $visit->zone->code, 'name' => $visit->zone->name] : null,
            'valid_from' => $visit->valid_from?->toIso8601String(),
            'valid_until' => $visit->valid_until?->toIso8601String(),
            'status' => $visit->status?->value ?? $visit->status,
            'checked_in_at' => $visit->checked_in_at?->toIso8601String(),
            'checked_out_at' => $visit->checked_out_at?->toIso8601String(),
            'created_at' => $visit->created_at?->toIso8601String(),
            'updated_at' => $visit->updated_at?->toIso8601String(),
        ];
    }
}
