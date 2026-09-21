<?php

namespace App\Http\Requests\Face;

use Illuminate\Foundation\Http\FormRequest;

class VerifyFaceEnrollmentRequest extends FormRequest
{
    /**
     * Prohibited fields that clients must never inject into verification.
     */
    protected const FORBIDDEN_FIELDS = [
        'status',
        'embedding',
        'quality_score',
        'model_id',
        'recognition_model_id',
        'enrolled_by_user_id',
        'person_id',
        'face_profile_id',
        'sample_id',
        'student_profile_id',
        'user_id',
        'access_decision',
        'verified',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'face_profile_uuid' => ['nullable', 'uuid'],
            'person_uuid' => ['nullable', 'uuid'],
            'status' => ['prohibited'],
            'embedding' => ['prohibited'],
            'quality_score' => ['prohibited'],
            'model_id' => ['prohibited'],
            'recognition_model_id' => ['prohibited'],
            'enrolled_by_user_id' => ['prohibited'],
            'person_id' => ['prohibited'],
            'face_profile_id' => ['prohibited'],
            'sample_id' => ['prohibited'],
            'student_profile_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'access_decision' => ['prohibited'],
            'verified' => ['prohibited'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach (self::FORBIDDEN_FIELDS as $field) {
                if ($this->has($field) || $this->exists($field)) {
                    $validator->errors()->add($field, "Field [{$field}] is prohibited and cannot be supplied by the client.");
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            '*.prohibited' => 'Security-sensitive or internal attributes cannot be supplied by the client.',
        ];
    }
}
