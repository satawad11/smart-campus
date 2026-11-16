<?php

namespace App\Http\Requests\Vehicle;

use App\Enums\VehicleRelation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkVehiclePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (vehicle.manage)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'person_uuid' => ['required', 'uuid'],
            'relation' => ['required', Rule::enum(VehicleRelation::class)],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'person_id' => ['prohibited'],
            'vehicle_id' => ['prohibited'],
        ];
    }
}
