<?php

namespace App\Http\Requests\Gate;

use App\Enums\GateCameraRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceGateCamerasRequest extends FormRequest
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
            // Present but may be empty (removes every camera from an inactive gate).
            'cameras' => ['present', 'array', 'max:8'],
            'cameras.*.camera_uuid' => ['required', 'uuid'],
            'cameras.*.role' => ['required', Rule::enum(GateCameraRole::class)],
        ];
    }
}
