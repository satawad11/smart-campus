<?php

namespace App\Http\Requests\Academic;

use App\Enums\AcademicStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (academic.manage)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Public identities; resolved to internal ids server-side. The service
            // also enforces that the program belongs to this department.
            'department_uuid' => ['required', 'string', Rule::exists('departments', 'uuid')],
            'program_uuid' => ['nullable', 'string', Rule::exists('programs', 'uuid')],
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'academic_year' => ['required', 'string', 'max:255'],
            'year_level' => ['required', 'integer', 'min:1', 'max:65535'],
            'status' => ['sometimes', Rule::enum(AcademicStatus::class)],

            // Internal identifiers / FK columns may never be client-supplied.
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'department_id' => ['prohibited'],
            'program_id' => ['prohibited'],
        ];
    }
}
