<?php

namespace App\Http\Requests\Role;

use App\Enums\RoleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::enum(RoleStatus::class)],

            // Immutable identity/classification fields (V1: role code is immutable).
            'code' => ['prohibited'],
            'system_role' => ['prohibited'],
            'uuid' => ['prohibited'],
            'id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }
}
