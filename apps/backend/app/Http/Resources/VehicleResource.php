<?php

namespace App\Http\Resources;

use App\Models\Vehicle;
use App\Support\Vehicles\ThaiPlate;
use App\Support\Vehicles\ThaiProvince;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vehicle registry representation (VEH-004). Public identity is uuid; internal ids are never
 * exposed. Links to persons are included only when loaded (detail endpoint).
 *
 * @mixin Vehicle
 */
class VehicleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'plate_number' => $this->plate_number,
            'plate_display' => ThaiPlate::tryNormalize($this->plate_number) !== null
                ? ThaiPlate::display($this->plate_number)
                : $this->plate_number,
            'plate_province' => $this->plate_province,
            'plate_province_name' => ThaiProvince::name((string) $this->plate_province),
            'vehicle_type' => $this->vehicle_type?->value,
            'brand' => $this->brand,
            'model' => $this->model,
            'color' => $this->color,
            'status' => $this->status?->value,
            'notes' => $this->notes,
            'links' => VehicleLinkResource::collection($this->whenLoaded('ownershipHistories')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
