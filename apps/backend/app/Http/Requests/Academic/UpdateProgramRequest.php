<?php

namespace App\Http\Requests\Academic;

use App\Enums\AcademicStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProgramRequest extends FormRequest
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
            'education_level' => ['sometimes', Rule::in(['VOC_CERT', 'HIGH_VOC_CERT', 'SHORT_COURSE', 'OTHER'])],
            'status' => ['sometimes', Rule::enum(AcademicStatus::class)],

            // Immutable business key, owning department, and internal identifiers.
            // Department is fixed at creation to avoid cross-department drift.
            'code' => ['prohibited'],
            'department_uuid' => ['prohibited'],
            'department_id' => ['prohibited'],
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
        ];
    }
}
