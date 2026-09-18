<?php

namespace App\Services\Auth;

use App\Enums\AuditAction;
use App\Models\User;
use App\Rules\PasswordPolicyRule;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reusable Password Service (AUTH-006, AUTH-015).
 *
 * Responsibilities:
 * - Validate new passwords against configured policy.
 * - Atomically update user password, password_changed_at timestamp,
 *   and must_change_password flag without partial mutations.
 * - Authenticated self password change with current password verification,
 *   other-session revocation, and transactional audit logging.
 */
class PasswordService
{
    public function __construct(
        protected ?SessionRevocationService $sessionService = null,
        protected ?AuditService $auditService = null,
    ) {
        $this->sessionService = $sessionService ?? app(SessionRevocationService::class);
        $this->auditService = $auditService ?? app(AuditService::class);
    }

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

    /**
     * Change authenticated user's own password with current password verification,
     * revocation of all other active sessions, and transactional audit logging.
     *
     * @throws ValidationException
     */
    public function changePassword(
        User $user,
        string $currentPassword,
        string $newPassword,
        ?string $currentSessionId = null,
        ?AuditRequestContext $context = null
    ): User {
        // 1. Verify current password
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The provided current password does not match our records.'],
            ]);
        }

        // 2. Reject if new password is identical to current password
        if (Hash::check($newPassword, $user->password) || $currentPassword === $newPassword) {
            throw ValidationException::withMessages([
                'password' => ['The new password cannot be the same as the current password.'],
            ]);
        }

        // 3. Validate against password policy
        $this->validatePassword($newPassword);

        // 4. Perform atomic update, session revocation, and audit logging
        return DB::transaction(function () use ($user, $currentPassword, $newPassword, $currentSessionId, $context) {
            $fresh = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            if (! Hash::check($currentPassword, $fresh->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['The provided current password does not match our records.'],
                ]);
            }

            $fresh->password = $newPassword;
            $fresh->password_changed_at = now();
            $fresh->must_change_password = false;
            $fresh->remember_token = null;
            $fresh->save();

            // Sync in-memory model
            $user->password = $fresh->password;
            $user->password_changed_at = $fresh->password_changed_at;
            $user->must_change_password = false;
            $user->remember_token = null;

            // Revoke all other active sessions while preserving current session
            $revokedCount = 0;
            if (! empty($currentSessionId)) {
                $revokedCount = $this->sessionService->revokeAllExcept($fresh, $currentSessionId);
            } else {
                $revokedCount = $this->sessionService->revokeAllForUser($fresh);
            }

            // Record audit log
            $this->auditService->record(
                AuditAction::USER_PASSWORD_CHANGED,
                $fresh,
                'USER',
                $fresh->uuid,
                newValues: [
                    'must_change_password' => false,
                ],
                metadata: [
                    'other_sessions_revoked' => $revokedCount,
                ],
                context: $context
            );

            return $fresh;
        });
    }
}
