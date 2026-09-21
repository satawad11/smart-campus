<?php

namespace App\Http\Requests\Face;

use App\Enums\DuplicateReviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SubmitDuplicateReviewDecisionRequest extends FormRequest
{
    protected const FORBIDDEN_FIELDS = [
        'source_face_profile_id',
        'candidate_face_profile_id',
        'recognition_model_id',
        'distance',
        'similarity',
        'reviewed_by_user_id',
        'embedding',
        'vector',
        'person_id',
        'user_id',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('decision') && is_string($this->input('decision'))) {
            $this->merge([
                'decision' => strtoupper($this->input('decision')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                new Enum(DuplicateReviewStatus::class),
            ],
            'review_note' => [
                'nullable',
                'string',
                'max:255',
            ],
            'source_face_profile_id' => ['prohibited'],
            'candidate_face_profile_id' => ['prohibited'],
            'recognition_model_id' => ['prohibited'],
            'distance' => ['prohibited'],
            'similarity' => ['prohibited'],
            'reviewed_by_user_id' => ['prohibited'],
            'embedding' => ['prohibited'],
            'vector' => ['prohibited'],
            'person_id' => ['prohibited'],
            'user_id' => ['prohibited'],
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
            'decision.required' => 'A valid review decision status is required.',
            '*.prohibited' => 'Security-sensitive or internal attributes cannot be supplied by the client.',
        ];
    }
}
