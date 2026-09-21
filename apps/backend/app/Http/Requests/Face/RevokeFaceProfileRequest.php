<?php

namespace App\Http\Requests\Face;

use Illuminate\Foundation\Http\FormRequest;

class RevokeFaceProfileRequest extends FormRequest
{
    protected const FORBIDDEN_FIELDS = [
        'id',
        'person_id',
        'student_profile_id',
        'user_id',
        'face_profile_id',
        'embedding',
        'recognition_model_id',
        'enrolled_by_user_id',
        'status',
        'access_decision',
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
            'reason' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],
            'person_id' => ['prohibited'],
            'student_profile_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'face_profile_id' => ['prohibited'],
            'embedding' => ['prohibited'],
            'recognition_model_id' => ['prohibited'],
            'enrolled_by_user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'access_decision' => ['prohibited'],
        ];
    }

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
            'reason.required' => 'A revocation reason is required.',
            'reason.min' => 'The revocation reason must be at least 3 characters.',
            'reason.max' => 'The revocation reason may not exceed 255 characters.',
            '*.prohibited' => 'Security-sensitive or internal attributes cannot be supplied by the client.',
        ];
    }
}
