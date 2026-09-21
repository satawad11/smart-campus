<?php

namespace App\Http\Requests\Face;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request for Restricted Face Recognition Diagnostic Testing (FACE-012).
 */
class RecognitionTestRequest extends FormRequest
{
    /**
     * Forbidden parameters that clients must never supply.
     */
    protected const FORBIDDEN_FIELDS = [
        'person_id',
        'person_uuid',
        'student_id',
        'student_uuid',
        'user_id',
        'face_profile_id',
        'face_profile_uuid',
        'embedding',
        'vector',
        'recognition_model_id',
        'model_id',
        'quality_score',
        'accepted',
        'status',
        'threshold',
        'match_threshold',
        'review_threshold',
        'decision',
        'access_decision',
        'actor_id',
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
            'top_k' => [
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],
            'person_id' => ['prohibited'],
            'person_uuid' => ['prohibited'],
            'student_id' => ['prohibited'],
            'student_uuid' => ['prohibited'],
            'user_id' => ['prohibited'],
            'face_profile_id' => ['prohibited'],
            'face_profile_uuid' => ['prohibited'],
            'embedding' => ['prohibited'],
            'vector' => ['prohibited'],
            'recognition_model_id' => ['prohibited'],
            'model_id' => ['prohibited'],
            'quality_score' => ['prohibited'],
            'accepted' => ['prohibited'],
            'status' => ['prohibited'],
            'threshold' => ['prohibited'],
            'match_threshold' => ['prohibited'],
            'review_threshold' => ['prohibited'],
            'decision' => ['prohibited'],
            'access_decision' => ['prohibited'],
            'actor_id' => ['prohibited'],
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
            'image.required' => 'A recognition test image file is required.',
            'image.file' => 'The uploaded input must be a valid file.',
            'image.mimes' => 'The recognition test image must be of type: jpeg, jpg, png, webp. SVG and arbitrary binaries are rejected.',
            'image.max' => 'The recognition test image must not exceed 10MB.',
            'top_k.integer' => 'The top_k parameter must be an integer.',
            'top_k.min' => 'The top_k parameter must be at least 1.',
            'top_k.max' => 'The top_k parameter must not exceed 50.',
            '*.prohibited' => 'Security-sensitive, internal, or policy-overriding attributes cannot be supplied by the client.',
        ];
    }
}
