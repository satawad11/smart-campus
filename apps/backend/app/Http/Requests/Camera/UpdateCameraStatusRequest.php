<?php

namespace App\Http\Requests\Camera;

use App\Enums\DeviceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Camera enable/disable request (CAM-002). Dedicated operation, distinct
 * permission (camera.enable) from generic update.
 */
class UpdateCameraStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (camera.enable)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(DeviceStatus::class)],
        ];
    }
}
