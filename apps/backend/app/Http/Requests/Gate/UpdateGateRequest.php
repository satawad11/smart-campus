<?php

namespace App\Http\Requests\Gate;

use App\Enums\GateDirection;
use App\Enums\LocationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (gate.manage)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'zone_uuid' => ['sometimes', 'uuid'],
            'name' => ['sometimes', 'string', 'max:255'],
            'direction' => ['sometimes', Rule::enum(GateDirection::class)],
            'correlation_window_seconds' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'status' => ['sometimes', Rule::enum(LocationStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // Site and code are fixed after creation; cameras change through PUT .../cameras.
            'site_uuid' => ['prohibited'],
            'code' => ['prohibited'],
            'mode' => ['prohibited'],
            'cameras' => ['prohibited'],
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'site_id' => ['prohibited'],
            'zone_id' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
        ];
    }
}
