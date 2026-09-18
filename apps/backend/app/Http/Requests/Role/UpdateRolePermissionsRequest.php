<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRolePermissionsRequest extends FormRequest
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
            // Permissions are addressed by stable code, never numeric id.
            // Unknown codes fail the whole request (exists). Duplicate codes are
            // collapsed by the service (array_unique + sync), so they never
            // produce duplicate pivots.
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,code'],
        ];
    }
}
