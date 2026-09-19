<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Generic Student update request (STD-007).
 *
 * Accepts only the approved mutable identity/profile fields. Academic status
 * (and its dates) are changed exclusively through the dedicated status
 * operation (STD-008), never here. Immutable business keys, Person record
 * status, User/account fields, academic relationship reassignment, and all
 * internal ids are rejected.
 */
class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (student.update)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'person' => ['sometimes', 'array'],
            'person.first_name' => ['sometimes', 'string', 'max:255'],
            'person.last_name' => ['sometimes', 'string', 'max:255'],
            'person.display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'person.gender' => ['sometimes', 'nullable', 'string', 'max:50'],

            'year_level' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'admission_year' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'graduation_year' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],

            // Status lifecycle is a dedicated operation (STD-008), not generic update.
            'academic_status' => ['prohibited'],
            'status_start_date' => ['prohibited'],
            'status_end_date' => ['prohibited'],

            // Immutable business keys and separate lifecycles.
            'student_code' => ['prohibited'],
            'person.person_code' => ['prohibited'],
            'person.person_type' => ['prohibited'],
            'person.status' => ['prohibited'],

            // Academic relationship reassignment is deferred (semantics undefined).
            'department_uuid' => ['prohibited'],
            'program_uuid' => ['prohibited'],
            'classroom_uuid' => ['prohibited'],
            'education_level' => ['prohibited'],

            // Internal ids / account / audit fields never accepted.
            'person_id' => ['prohibited'],
            'person.id' => ['prohibited'],
            'department_id' => ['prohibited'],
            'program_id' => ['prohibited'],
            'classroom_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'id' => ['prohibited'],
            'changed_by_user_id' => ['prohibited'],
            'source' => ['prohibited'],
        ];
    }
}
