<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\UserSession;
use App\Services\Audit\AuditRequestContext;
use App\Services\Auth\SessionRevocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use stdClass;

/**
 * Authenticated User Own Session Management API (AUTH-012).
 *
 * Allows authenticated users to view their active and recent sessions,
 * revoke specific own sessions, or revoke all other sessions.
 */
class SessionController extends Controller
{
    public function __construct(
        protected SessionRevocationService $service,
    ) {}

    /**
     * List all tracked application sessions for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

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
     * Revoke one of the authenticated user's own sessions.
     */
    public function destroy(Request $request, UserSession $session): JsonResponse
    {
        $user = $request->user();

        // Enforce ownership: session must belong to the authenticated user
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
            $user,
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
     * Revoke all active sessions for the authenticated user except the current one.
     */
    public function revokeOthers(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        $revokedCount = $this->service->revokeAllExcept(
            $user,
            $currentSessionId,
            $user,
            AuditRequestContext::fromRequest($request)
        );

        return response()->json([
            'success' => true,
            'message' => 'Other sessions revoked successfully',
            'data' => [
                'revoked_count' => $revokedCount,
            ],
            'meta' => null,
        ]);
    }
}
