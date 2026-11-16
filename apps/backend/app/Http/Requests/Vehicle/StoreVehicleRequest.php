<?php

namespace App\Http\Requests\Vehicle;

use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Support\Vehicles\ThaiPlate;
use App\Support\Vehicles\ThaiProvince;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
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
            'plate_number' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail) {
                if (! ThaiPlate::isValid((string) $value)) {
                    $fail('ทะเบียนรถไม่ถูกต้อง');
                }
            }],
            'plate_province' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail) {
                if (ThaiProvince::resolve((string) $value) === null) {
                    $fail('ไม่พบจังหวัดนี้');
                }
            }],
            'vehicle_type' => ['sometimes', Rule::enum(VehicleType::class)],
            'brand' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::enum(VehicleStatus::class)],
            'notes' => ['nullable', 'string', 'max:1000'],

            // Internal identifiers may never be client-supplied.
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
        ];
    }
}
