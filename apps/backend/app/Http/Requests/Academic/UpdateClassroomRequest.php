<?php

namespace App\Http\Requests\Academic;

use App\Enums\AcademicStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassroomRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'year_level' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'status' => ['sometimes', Rule::enum(AcademicStatus::class)],
            // Program link may be changed or cleared; the service re-validates that
            // the target program belongs to this classroom's department.
            'program_uuid' => ['sometimes', 'nullable', 'string', Rule::exists('programs', 'uuid')],

            // Immutable business key, academic year, department, and internal ids.
            'code' => ['prohibited'],
            'academic_year' => ['prohibited'],
            'department_uuid' => ['prohibited'],
            'department_id' => ['prohibited'],
            'program_id' => ['prohibited'],
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
        ];
    }
}
