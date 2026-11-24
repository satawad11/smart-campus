<?php

namespace App\Http\Requests\Gate;

use App\Enums\GateCameraRole;
use App\Enums\GateDirection;
use App\Enums\LocationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGateRequest extends FormRequest
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
            'site_uuid' => ['required', 'uuid'],
            'zone_uuid' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'direction' => ['sometimes', Rule::enum(GateDirection::class)],
            'correlation_window_seconds' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'status' => ['sometimes', Rule::enum(LocationStatus::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'cameras' => ['sometimes', 'array', 'max:8'],
            'cameras.*.camera_uuid' => ['required', 'uuid'],
            'cameras.*.role' => ['required', Rule::enum(GateCameraRole::class)],

            // FACE_PRIMARY is the only approved mode (D2); internal identifiers are never client-supplied.
            'mode' => ['prohibited'],
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'site_id' => ['prohibited'],
            'zone_id' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
        ];
    }
}
