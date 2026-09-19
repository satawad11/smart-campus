<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create-Student request (STD-007).
 *
 * Accepts canonical public values only. Related entities are addressed by
 * public UUID and resolved server-side by StudentService; internal ids are
 * never accepted. Per the approved STD-006 workflow a newly created Student is
 * ACTIVE, so create-time academic_status / source / effective_from are not
 * accepted here, and existing-Person linking (person_uuid) is deferred.
 */
class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (student.create)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'person' => ['required', 'array'],
            'person.person_code' => ['nullable', 'string', 'max:255'],
            'person.first_name' => ['required', 'string', 'max:255'],
            'person.last_name' => ['required', 'string', 'max:255'],
            'person.display_name' => ['nullable', 'string', 'max:255'],
            'person.gender' => ['nullable', 'string', 'max:50'],

            'student_code' => ['required', 'string', 'max:255'],
            'department_uuid' => ['required', 'string', Rule::exists('departments', 'uuid')],
            'program_uuid' => ['nullable', 'string', Rule::exists('programs', 'uuid')],
            'classroom_uuid' => ['nullable', 'string', Rule::exists('classrooms', 'uuid')],
            'education_level' => ['required', Rule::in(['VOC_CERT', 'HIGH_VOC_CERT', 'SHORT_COURSE', 'OTHER'])],
            'year_level' => ['required', 'integer', 'min:1', 'max:65535'],
            'admission_year' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'graduation_year' => ['nullable', 'integer', 'min:1', 'max:65535'],

            // Approved workflow controls status/source/time server-side.
            'academic_status' => ['prohibited'],
            'status_effective_from' => ['prohibited'],
            'status_start_date' => ['prohibited'],
            'status_end_date' => ['prohibited'],
            'source' => ['prohibited'],

            // Existing-person linking is deferred; internal ids never accepted.
            'person_uuid' => ['prohibited'],
            'person_id' => ['prohibited'],
            'person.id' => ['prohibited'],
            'person.uuid' => ['prohibited'],
            'person.person_type' => ['prohibited'],
            'person.status' => ['prohibited'],
            'department_id' => ['prohibited'],
            'program_id' => ['prohibited'],
            'classroom_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'id' => ['prohibited'],
            'changed_by_user_id' => ['prohibited'],
        ];
    }
}
