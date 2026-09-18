<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        $userId = $this->route('user')?->id;

        return [
            'username' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($userId)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],

            // Credentials, MFA, and security-state fields are not editable here.
            'password' => ['prohibited'],
            'status' => ['prohibited'],
            'mfa_enabled' => ['prohibited'],
            'mfa_secret_encrypted' => ['prohibited'],
            'failed_login_count' => ['prohibited'],
            'locked_until' => ['prohibited'],
            'last_login_at' => ['prohibited'],
            'password_changed_at' => ['prohibited'],
            'must_change_password' => ['prohibited'],
            'uuid' => ['prohibited'],
            'id' => ['prohibited'],
            'person_id' => ['prohibited'],
        ];
    }
}
