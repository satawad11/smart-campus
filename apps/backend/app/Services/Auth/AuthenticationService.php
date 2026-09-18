<?php

namespace App\Services\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Authentication Service (AUTH-004).
 *
 * Implements domain-level credential verification and account eligibility checks.
 * Session creation, MFA TOTP verification, and HTTP responses are deferred to AUTH-005+.
 */
class AuthenticationService
{
    /**
     * Dummy bcrypt hash used for non-existent users to mitigate timing attacks
     * and prevent username enumeration.
     */
    protected const DUMMY_HASH = '$2y$12$e8hG7z5Kq0iP9gU6W1r2OuJ1fE9lM4vB8yT3sD6aN2cK7oR5vG0mG';

    /**
     * Authenticate a user by username and password.
     */
    public function authenticate(string $username, string $password): AuthenticationResult
    {
        // 1. Find user by username
        $user = User::where('username', $username)->first();

        // 2. Mitigate timing enumeration if user does not exist
        if (! $user) {
            Hash::check($password, self::DUMMY_HASH);

            return AuthenticationResult::invalidCredentials(internalReason: 'USER_NOT_FOUND');
        }

        // 3. Evaluate persistent account status
        if ($user->status === UserStatus::DISABLED) {
            return AuthenticationResult::accountDisabled(user: $user, internalReason: 'STATUS_DISABLED');
        }

        if ($user->status === UserStatus::EXPIRED) {
            return AuthenticationResult::accountExpired(user: $user, internalReason: 'STATUS_EXPIRED');
        }

        if ($user->status === UserStatus::LOCKED) {
            return AuthenticationResult::accountLocked(user: $user, internalReason: 'STATUS_LOCKED');
        }

        // 4. Evaluate temporary rate-limit lock
        if ($user->locked_until !== null && $user->locked_until->isFuture()) {
            return AuthenticationResult::accountLocked(user: $user, internalReason: 'TEMPORARY_LOCKED');
        }

        // 5. Verify password using framework hasher
        if (! Hash::check($password, $user->password)) {
            $this->handleFailedAttempt($user);

            return AuthenticationResult::invalidCredentials(internalReason: 'WRONG_PASSWORD', user: $user);
        }

        // 6. Successful credential check: reset failed attempts and temporary lock
        $this->resetFailedAttempts($user);

        // 7. Evaluate MFA requirement
        if ($user->mfa_enabled) {
            return AuthenticationResult::mfaRequired($user);
        }

        return AuthenticationResult::success($user);
    }

    /**
     * Atomically increment failed login count and apply temporary lockout if threshold reached.
     */
    protected function handleFailedAttempt(User $user): void
    {
        $maxAttempts = (int) config('auth_security.max_failed_attempts', 5);
        $lockMinutes = (int) config('auth_security.lock_minutes', 15);

        DB::transaction(function () use ($user, $maxAttempts, $lockMinutes) {
            $fresh = User::where('id', $user->id)->lockForUpdate()->first();
            if (! $fresh) {
                return;
            }

            // If an earlier lock expired, reset stale count before recording this attempt
            $currentCount = ($fresh->locked_until !== null && $fresh->locked_until->isPast())
                ? 0
                : (int) $fresh->failed_login_count;

            $newCount = $currentCount + 1;
            $lockedUntil = null;

            if ($newCount >= $maxAttempts) {
                $lockedUntil = now()->addMinutes($lockMinutes);
            }

            $fresh->forceFill([
                'failed_login_count' => $newCount,
                'locked_until' => $lockedUntil,
            ])->save();

            $user->failed_login_count = $newCount;
            $user->locked_until = $lockedUntil;
        });
    }

    /**
     * Reset failed login count and clear temporary lockout upon valid credentials.
     */
    protected function resetFailedAttempts(User $user): void
    {
        if ($user->failed_login_count > 0 || $user->locked_until !== null) {
            DB::transaction(function () use ($user) {
                $fresh = User::where('id', $user->id)->lockForUpdate()->first();
                if ($fresh) {
                    $fresh->forceFill([
                        'failed_login_count' => 0,
                        'locked_until' => null,
                    ])->save();
                }
            });

            $user->failed_login_count = 0;
            $user->locked_until = null;
        }
    }
}
