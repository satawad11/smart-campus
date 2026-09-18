<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Rules\PasswordPolicyRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reusable Password Service (AUTH-006).
 *
 * Responsibilities:
 * - Validate new passwords against configured policy.
 * - Atomically update user password, password_changed_at timestamp,
 *   and must_change_password flag without partial mutations.
 */
class PasswordService
{
    /**
     * Get the validation rules for password policy.
     *
     * @return array<int, mixed>
     */
    public function validationRules(): array
    {
        return [
            'required',
            'string',
            new PasswordPolicyRule,
        ];
    }

    /**
     * Validate a candidate password against the configured policy.
     *
     * @throws ValidationException
     */
    public function validatePassword(string $password): void
    {
        $validator = Validator::make(
            ['password' => $password],
            ['password' => $this->validationRules()],
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * Check if a candidate password satisfies the configured policy.
     */
    public function isValidPassword(string $password): bool
    {
        return ! Validator::make(
            ['password' => $password],
            ['password' => $this->validationRules()],
        )->fails();
    }

    /**
     * Atomically update a user's password.
     *
     * @param  User  $user  The user to update
     * @param  string  $newPassword  The plaintext new password to validate and set
     * @param  bool  $mustChangePassword  Whether to force password change on next login
     * @return User The updated user instance
     *
     * @throws ValidationException
     */
    public function updatePassword(User $user, string $newPassword, bool $mustChangePassword = false): User
    {
        // 1. Validate against policy first. Throws ValidationException on failure,
        // ensuring NO partial database mutation or state changes occur.
        $this->validatePassword($newPassword);

        // 2. Perform atomic update in transaction
        return DB::transaction(function () use ($user, $newPassword, $mustChangePassword) {
            $fresh = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $fresh->password = $newPassword;
            $fresh->password_changed_at = now();
            $fresh->must_change_password = $mustChangePassword;
            $fresh->save();

            // Sync original in-memory instance
            $user->password = $fresh->password;
            $user->password_changed_at = $fresh->password_changed_at;
            $user->must_change_password = $fresh->must_change_password;

            return $fresh;
        });
    }
}
