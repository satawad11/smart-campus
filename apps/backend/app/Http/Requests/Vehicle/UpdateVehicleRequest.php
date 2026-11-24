<?php

namespace App\Http\Requests\Vehicle;

use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
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
            'vehicle_type' => ['sometimes', Rule::enum(VehicleType::class)],
            'brand' => ['sometimes', 'nullable', 'string', 'max:64'],
            'model' => ['sometimes', 'nullable', 'string', 'max:64'],
            'color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::enum(VehicleStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // The plate is fixed after registration (deactivate and register the correct plate).
            'plate_number' => ['prohibited'],
            'plate_province' => ['prohibited'],
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
        ];
    }
}
