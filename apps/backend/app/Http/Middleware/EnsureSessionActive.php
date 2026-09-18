<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce application session revocation (AUTH-010 final invariant).
 *
 * For EVERY authenticated first-party request the current server-side session
 * id must correspond to an active, non-revoked user_sessions record for the
 * authenticated user. FAIL CLOSED:
 *   - active, non-revoked matching record -> allow
 *   - matching record revoked              -> 401
 *   - no matching record                   -> 401
 *
 * On rejection the guard is logged out, the session invalidated, and the CSRF
 * token rotated; the response is 401 UNAUTHENTICATED with no session identifier
 * or revocation reason disclosed. The check is unconditional for authenticated
 * requests — there is no escape hatch.
 */
class EnsureSessionActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $active = UserSession::query()
                ->where('user_id', $user->id)
                ->where('session_id', $request->session()->getId())
                ->whereNull('revoked_at')
                ->exists();

            if (! $active) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                        'fields' => new stdClass,
                    ],
                ], 401);
            }
        }

        return $next($request);
    }
}
