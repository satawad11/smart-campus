<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (staff.create)
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

            'person_type' => ['nullable', Rule::in(['TEACHER', 'STAFF'])],
            'staff_code' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'department_uuid' => ['bail', 'nullable', 'string', 'uuid', Rule::exists('departments', 'uuid')],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],

            // Status, source and time are controlled server-side; ids never accepted.
            'employment_status' => ['prohibited'],
            'status_start_date' => ['prohibited'],
            'source' => ['prohibited'],
            'person_uuid' => ['prohibited'],
            'person_id' => ['prohibited'],
            'person.id' => ['prohibited'],
            'person.uuid' => ['prohibited'],
            'person.person_type' => ['prohibited'],
            'person.status' => ['prohibited'],
            'department_id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'id' => ['prohibited'],
            'changed_by_user_id' => ['prohibited'],
        ];
    }
}
