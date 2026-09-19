<?php

namespace App\Http\Requests\Academic;

use App\Enums\AcademicStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProgramRequest extends FormRequest
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
            // Public identity of the owning department; resolved to the internal
            // id server-side. Existence is confirmed against the uuid column only.
            'department_uuid' => ['required', 'string', Rule::exists('departments', 'uuid')],
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'education_level' => ['required', Rule::in(['VOC_CERT', 'HIGH_VOC_CERT', 'SHORT_COURSE', 'OTHER'])],
            'status' => ['sometimes', Rule::enum(AcademicStatus::class)],

            // Internal identifiers / FK columns may never be client-supplied.
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'department_id' => ['prohibited'],
        ];
    }
}
