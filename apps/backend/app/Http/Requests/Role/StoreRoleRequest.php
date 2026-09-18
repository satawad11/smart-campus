<?php

namespace App\Http\Requests\Role;

use App\Enums\RoleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware
    }

    /**
     * Normalize the role code to uppercase snake-case before validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('code') && is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z][A-Z0-9_]*$/', 'unique:roles,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::enum(RoleStatus::class)],

            // Client may not create privileged/system roles or inject identity.
            'system_role' => ['prohibited'],
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }
}
