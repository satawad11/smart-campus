<?php

namespace App\Http\Requests\Academic;

use App\Enums\AcademicStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:255', 'unique:departments,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::enum(AcademicStatus::class)],

            // Internal identifiers may never be client-supplied.
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
        ];
    }
}
