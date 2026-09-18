<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Audit\AuditRequestContext;
use App\Services\Auth\SessionRevocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use stdClass;

/**
 * Administrative User Session Management API (AUTH-012).
 *
 * Allows administrators with appropriate permissions to inspect and force-revoke
 * user sessions. All public identification uses UUIDs.
 */
class UserSessionController extends Controller
{
    public function __construct(
        protected SessionRevocationService $service,
    ) {}

    /**
     * List all tracked application sessions for the specified user.
     */
    public function index(Request $request, User $user): JsonResponse
    {
        $sessions = UserSession::query()
            ->where('user_id', $user->id)
            ->orderBy('login_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => SessionResource::collection($sessions),
            'meta' => null,
        ]);
    }

    /**
     * Administratively force-revoke a specific session for the target user.
     */
    public function destroy(Request $request, User $user, UserSession $session): JsonResponse
    {
        // Enforce user ownership: session must belong to the specified target user
        if ($session->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found',
                'error' => [
                    'code' => 'NOT_FOUND',
                    'fields' => new stdClass,
                ],
            ], 404);
        }

        $isCurrent = $request->hasSession() && $session->session_id === $request->session()->getId();

        $this->service->revokeSession(
            $session,
            $request->user(),
            AuditRequestContext::fromRequest($request)
        );

        if ($isCurrent) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'success' => true,
            'message' => 'Session revoked successfully',
            'data' => null,
            'meta' => null,
        ]);
    }

    /**
     * Administratively force-revoke all active sessions for the target user.
     */
    public function revokeAll(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();
        $isSelfTarget = $currentUser && $currentUser->id === $user->id;

        $revokedCount = $this->service->revokeAllForUser(
            $user,
            $currentUser,
            AuditRequestContext::fromRequest($request)
        );

        if ($isSelfTarget) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'success' => true,
            'message' => 'All user sessions revoked successfully',
            'data' => [
                'revoked_count' => $revokedCount,
            ],
            'meta' => null,
        ]);
    }
}
