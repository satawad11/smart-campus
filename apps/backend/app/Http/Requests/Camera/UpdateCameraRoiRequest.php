<?php

namespace App\Http\Requests\Camera;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Camera ROI (region of interest) update request (CAM-016). A dedicated
 * operation, distinct from generic camera update — mirrors
 * UpdateCameraStatusRequest's pattern. `points: null` clears the ROI
 * (process the whole frame again); when present, at least 3 normalized
 * (0.0-1.0) vertices are required to form a polygon.
 */
class UpdateCameraRoiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (camera.update)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'points' => ['present', 'nullable', 'array', 'min:3'],
            'points.*.x' => ['required_with:points', 'numeric', 'min:0', 'max:1'],
            'points.*.y' => ['required_with:points', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
