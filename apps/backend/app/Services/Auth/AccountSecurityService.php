<?php

namespace App\Services\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Account Security Service (AUTH-006).
 *
 * Responsibilities:
 * - Manage persistent administrative account locking (ACTIVE -> LOCKED).
 * - Manage administrative unlocking (LOCKED -> ACTIVE) with automatic reset of
 *   temporary failed-login counters (failed_login_count = 0, locked_until = null).
 * - Enforce lifecycle boundaries so that DISABLED and EXPIRED accounts cannot be
 *   modified via lock/unlock operations.
 */
class AccountSecurityService
{
    /**
     * Administratively and persistently lock a user account.
     *
     * Sets status = UserStatus::LOCKED without using locked_until.
     *
     * @throws InvalidArgumentException When account is DISABLED or EXPIRED
     */
    public function lockUser(User $user): User
    {
        if ($user->status === UserStatus::DISABLED || $user->status === UserStatus::EXPIRED) {
            throw new InvalidArgumentException(
                "Cannot lock account with status [{$user->status->value}]. Only active accounts may be locked."
            );
        }

        if ($user->status === UserStatus::LOCKED) {
            return $user;
        }

        return DB::transaction(function () use ($user) {
            $fresh = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $fresh->status = UserStatus::LOCKED;
            $fresh->save();

            // Sync original in-memory instance
            $user->status = $fresh->status;

            return $fresh;
        });
    }

    /**
     * Administratively unlock a persistently locked account.
     *
     * Sets status = UserStatus::ACTIVE and resets temporary lockout state:
     * - failed_login_count = 0
     * - locked_until = null
     *
     * @throws InvalidArgumentException When account is DISABLED or EXPIRED
     */
    public function unlockUser(User $user): User
    {
        if ($user->status === UserStatus::DISABLED || $user->status === UserStatus::EXPIRED) {
            throw new InvalidArgumentException(
                "Cannot unlock account with status [{$user->status->value}]. Disabled and expired accounts have distinct lifecycles."
            );
        }

        return DB::transaction(function () use ($user) {
            $fresh = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $fresh->status = UserStatus::ACTIVE;
            $fresh->failed_login_count = 0;
            $fresh->locked_until = null;
            $fresh->save();

            // Sync original in-memory instance
            $user->status = $fresh->status;
            $user->failed_login_count = $fresh->failed_login_count;
            $user->locked_until = $fresh->locked_until;

            return $fresh;
        });
    }

    /**
     * Check if a user account is persistently locked via administrative status.
     */
    public function isPersistentlyLocked(User $user): bool
    {
        return $user->status === UserStatus::LOCKED;
    }

    /**
     * Check if a user account is temporarily locked due to failed login attempts.
     */
    public function isTemporarilyLocked(User $user): bool
    {
        return $user->locked_until !== null && $user->locked_until->isFuture();
    }
}
