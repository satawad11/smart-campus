<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-safe active-account enforcement (AUTH-010).
 *
 * Ensures every authenticated request is rejected when the account is not
 * ACTIVE (or is temporarily locked), so an administratively DISABLED/LOCKED/
 * EXPIRED user with a previously established session cannot continue. Applied
 * to authenticated routes regardless of whether they require a business
 * permission (permission checks already fail closed for non-ACTIVE users).
 */
class EnsureUserActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $inactive = $user->status !== UserStatus::ACTIVE
                || ($user->locked_until !== null && $user->locked_until->isFuture());

            if ($inactive) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account is not active',
                    'error' => [
                        'code' => 'ACCOUNT_INACTIVE',
                        'fields' => new stdClass,
                    ],
                ], 403);
            }
        }

        return $next($request);
    }
}
