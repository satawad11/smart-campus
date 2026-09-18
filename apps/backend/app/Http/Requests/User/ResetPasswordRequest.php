<?php

namespace App\Http\Requests\User;

use App\Rules\PasswordPolicyRule;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
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
            'password' => ['required', 'string', new PasswordPolicyRule],
        ];
    }
}
