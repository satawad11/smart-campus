<?php

namespace App\Http\Middleware;

use App\Services\Auth\PermissionService;
use Closure;
use Illuminate\Http\Request;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route Permission Enforcement Middleware (AUTH-008).
 *
 * Verifies that the authenticated user possesses the required permission(s).
 * Rejects unauthorized requests with 403 PERMISSION_DENIED.
 */
class RequirePermission
{
    public function __construct(
        protected PermissionService $permissionService,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'fields' => new stdClass,
                ],
            ], 401);
        }

        // V1 audit A-04: a forced password change (admin reset / bootstrap) must
        // be completed before any permission-protected action. Previously only
        // the frontend router enforced it. Self-service routes (me, logout,
        // change-password, MFA, sessions) carry no permission middleware.
        if ($user->must_change_password) {
            return response()->json([
                'success' => false,
                'message' => 'Password change required',
                'error' => [
                    'code' => 'PASSWORD_CHANGE_REQUIRED',
                    'fields' => new stdClass,
                ],
            ], 403);
        }

        $hasPermission = false;

        foreach ($permissions as $permission) {
            if ($this->permissionService->hasPermission($user, $permission)) {
                $hasPermission = true;
                break;
            }
        }

        if (! $hasPermission) {
            return response()->json([
                'success' => false,
                'message' => 'Permission denied',
                'error' => [
                    'code' => 'PERMISSION_DENIED',
                    'fields' => new stdClass,
                ],
            ], 403);
        }

        return $next($request);
    }
}
