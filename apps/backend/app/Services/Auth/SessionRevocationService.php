<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Application session revocation (AUTH-010, bounded).
 *
 * Marks a user's tracked application sessions (user_sessions) as revoked. This
 * is the strongest safe behavior available now: combined with the
 * EnsureUserActive middleware, a non-ACTIVE user cannot continue protected
 * access regardless of the configured Laravel session store.
 *
 * Boundary: purging entries from the framework's own session store (redis/db)
 * and a full session-management API are AUTH-012. This service only records the
 * revocation on the application-level session records.
 */
class SessionRevocationService
{
    /**
     * Revoke all active application sessions for the user. Returns the number
     * of sessions marked revoked.
     */
    public function revokeAllForUser(User $user): int
    {
        return DB::table('user_sessions')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->whereNull('logout_at')
            ->update(['revoked_at' => now()]);
    }
}
