<?php

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;

class UnlinkVehiclePersonRequest extends FormRequest
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
            'effective_to' => ['sometimes', 'nullable', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
