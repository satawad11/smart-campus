<?php

namespace App\Http\Requests\Face;

use App\Enums\FaceSamplePose;
use App\Enums\FaceSampleSource;
use App\Rules\MalwareFreeUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class EnrollFaceRequest extends FormRequest
{
    /**
     * Forbidden parameters that clients must never supply.
     */
    protected const FORBIDDEN_FIELDS = [
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
                new MalwareFreeUpload,
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
            'image.required' => 'An enrollment image file is required.',
            'image.image' => 'The uploaded file must be a valid image (JPEG, PNG, or WebP).',
            'image.mimes' => 'The enrollment image must be of type: jpeg, jpg, png, webp. SVG and arbitrary binaries are rejected.',
            'image.max' => 'The enrollment image must not exceed 10MB.',
            '*.prohibited' => 'Security-sensitive or internal attributes cannot be supplied by the client.',
        ];
    }
}
