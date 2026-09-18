<?php

namespace App\Http\Requests\User;

use App\Enums\UserStatus;
use App\Rules\PasswordPolicyRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', new PasswordPolicyRule],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'must_change_password' => ['sometimes', 'boolean'],

            // Security-controlled fields may never be client-supplied.
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'person_id' => ['prohibited'],
            'failed_login_count' => ['prohibited'],
            'locked_until' => ['prohibited'],
            'last_login_at' => ['prohibited'],
            'password_changed_at' => ['prohibited'],
            'mfa_enabled' => ['prohibited'],
            'mfa_secret_encrypted' => ['prohibited'],
        ];
    }
}
