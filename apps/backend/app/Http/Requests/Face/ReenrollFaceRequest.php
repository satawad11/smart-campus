<?php

namespace App\Http\Requests\Face;

use App\Enums\FaceSamplePose;
use App\Enums\FaceSampleSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ReenrollFaceRequest extends FormRequest
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
        'enrolled_by',
        'quality_score',
        'accepted',
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
            'image' => [
                'required',
                'file',
                'mimes:jpeg,jpg,png,webp',
                'max:10240', // 10MB
            ],
            'reason' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],
            'pose' => [
                'nullable',
                new Enum(FaceSamplePose::class),
            ],
            'source' => [
                'nullable',
                new Enum(FaceSampleSource::class),
            ],
            'person_id' => ['prohibited'],
            'student_profile_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'face_profile_id' => ['prohibited'],
            'embedding' => ['prohibited'],
            'recognition_model_id' => ['prohibited'],
            'enrolled_by_user_id' => ['prohibited'],
            'enrolled_by' => ['prohibited'],
            'quality_score' => ['prohibited'],
            'accepted' => ['prohibited'],
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
            'image.required' => 'A biometric image file is required for re-enrollment.',
            'image.mimes' => 'The image must be of type: jpeg, jpg, png, webp.',
            'image.max' => 'The image must not exceed 10MB.',
            'reason.required' => 'A re-enrollment reason is required.',
            'reason.min' => 'The re-enrollment reason must be at least 3 characters.',
            'reason.max' => 'The re-enrollment reason may not exceed 255 characters.',
            '*.prohibited' => 'Security-sensitive or internal attributes cannot be supplied by the client.',
        ];
    }
}
