<?php

namespace App\Http\Requests\Camera;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pre-save stream preview on the add-camera form. Takes the same
 * connection fields as StoreCameraRequest; nothing is persisted.
 */
class PreviewCameraStreamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (camera.create)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rtsp_url' => ['required', 'string', 'max:2048', 'regex:/^(rtsps?|https?):\/\//i'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ];
    }
}
