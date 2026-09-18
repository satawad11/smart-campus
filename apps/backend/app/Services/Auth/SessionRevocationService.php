<?php

namespace App\Services\Auth;

use App\Enums\AuditAction;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Application session revocation & management service (AUTH-010, AUTH-012, AUTH-014).
 *
 * Marks a user's tracked application sessions (user_sessions) as revoked.
 * Combined with EnsureSessionActive middleware, a revoked session is rejected
 * with 401 UNAUTHENTICATED on its next request.
 */
class SessionRevocationService
{
    public function __construct(
        protected ?AuditService $auditService = null,
    ) {
        $this->auditService = $auditService ?? app(AuditService::class);
    }

    /**
     * Revoke a single application session. Idempotent.
     */
    public function revokeSession(UserSession $session, ?User $actor = null, ?AuditRequestContext $context = null): bool
    {
        if ($session->revoked_at !== null) {
            return true;
        }

        $session->forceFill(['revoked_at' => now()])->save();

        if ($actor !== null) {
            $userUuid = $session->user?->uuid ?? User::find($session->user_id)?->uuid;
            $this->auditService->record(
                AuditAction::SESSION_REVOKED,
                $actor,
                'SESSION',
                $session->uuid,
                metadata: [
                    'user_uuid' => $userUuid,
                ],
                context: $context
            );
        }

        return true;
    }

    /**
     * Revoke all active application sessions for the user. Returns the number
     * of sessions marked revoked.
     */
    public function revokeAllForUser(User $user, ?User $actor = null, ?AuditRequestContext $context = null): int
    {
        $count = DB::table('user_sessions')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->whereNull('logout_at')
            ->update(['revoked_at' => now()]);

        if ($actor !== null) {
            $this->auditService->record(
                AuditAction::USER_SESSIONS_REVOKED,
                $actor,
                'USER',
                $user->uuid,
                newValues: ['revoked_count' => $count],
                context: $context
            );
        }

        return $count;
    }

    /**
     * Revoke all active application sessions for the user except the specified session.
     * Returns the number of sessions marked revoked.
     */
    public function revokeAllExcept(User $user, string $currentSessionId, ?User $actor = null, ?AuditRequestContext $context = null): int
    {
        $count = DB::table('user_sessions')
            ->where('user_id', $user->id)
            ->where('session_id', '!=', $currentSessionId)
            ->whereNull('revoked_at')
            ->whereNull('logout_at')
            ->update(['revoked_at' => now()]);

        if ($actor !== null) {
            $this->auditService->record(
                AuditAction::OTHER_SESSIONS_REVOKED,
                $actor,
                'USER',
                $user->uuid,
                newValues: ['revoked_count' => $count],
                context: $context
            );
        }

        return $count;
    }

    /**
     * Check if a session record is currently active (neither revoked nor logged out).
     */
    public function isActive(UserSession $session): bool
    {
        return $session->revoked_at === null && $session->logout_at === null;
    }
}
