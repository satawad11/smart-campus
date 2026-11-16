<?php

namespace App\Http\Requests\Face;

use App\Rules\MalwareFreeUpload;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One captured webcam frame for check-quality / recognize (FACE-022).
 *
 * The probe is the image only: identity hints, vectors, thresholds and decisions
 * can never be supplied by the client.
 */
class FaceScanImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'image' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:5120', new MalwareFreeUpload],
        ];

        foreach ([
            'person_id', 'person_uuid', 'student_id', 'student_uuid', 'user_id',
            'face_profile_id', 'face_profile_uuid', 'embedding', 'vector', 'model_id',
            'recognition_model_id', 'threshold', 'match_threshold', 'review_threshold',
            'classification', 'decision', 'access_decision',
        ] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
