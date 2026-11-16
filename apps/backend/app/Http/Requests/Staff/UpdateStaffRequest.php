<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (staff.update)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'person' => ['sometimes', 'array'],
            'person.first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'person.last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'person.display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'person.gender' => ['sometimes', 'nullable', 'string', 'max:50'],

            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'department_uuid' => ['bail', 'sometimes', 'nullable', 'string', 'uuid', Rule::exists('departments', 'uuid')],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],

            // Identity keys and status have their own flows.
            'staff_code' => ['prohibited'],
            'person_type' => ['prohibited'],
            'employment_status' => ['prohibited'],
            'status_start_date' => ['prohibited'],
            'person.person_code' => ['prohibited'],
            'person.person_type' => ['prohibited'],
            'person.status' => ['prohibited'],
            'person.id' => ['prohibited'],
            'person.uuid' => ['prohibited'],
            'person_id' => ['prohibited'],
            'department_id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'id' => ['prohibited'],
        ];
    }
}
