<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\UserSession;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Authentication Controller (AUTH-005).
 *
 * Handles HTTP login, logout, and authenticated user profile endpoints.
 * Session state is managed via Laravel web guard session cookies.
 */
class AuthController extends Controller
{
    public function __construct(
        protected AuthenticationService $authService,
    ) {}

    /**
     * Authenticate credentials and establish a user session.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->authenticate(
            $request->validated('username'),
            $request->validated('password')
        );

        if ($result->isSuccessful()) {
            $user = $result->getUser();

            // Clear any pending MFA challenge state
            $this->authService->clearPendingMfaState($request->session());

            // 1. Establish authenticated web session and regenerate ID to prevent fixation
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            // 2. Create application-level user_sessions record keyed by the
            //    current session id — EnsureSessionActive enforces revocation
            //    by matching this session id.
            UserSession::create([
                'user_id' => $user->id,
                'session_id' => $request->session()->getId(),
                'ip_address' => $request->ip() ?? '127.0.0.1',
                'user_agent' => $request->userAgent() ?? 'unknown',
                'device_name' => null,
                'login_at' => now(),
                'last_activity_at' => now(),
            ]);

            // 3. Update last_login_at timestamp on user
            $user->forceFill(['last_login_at' => now()])->save();

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => new UserResource($user->load('roles')),
                ],
                'meta' => null,
            ]);
        }

        if ($result->requiresMfa()) {
            $user = $result->getUser();
            $mfaMinutes = (int) config('auth_security.mfa_challenge_minutes', 5);

            // Store pending MFA state in session
            $this->authService->setPendingMfaState($request->session(), $user);

            // Return MFA challenge without creating session or updating last_login_at
            return response()->json([
                'success' => true,
                'message' => 'MFA verification required',
                'data' => [
                    'status' => 'MFA_REQUIRED',
                    'challenge' => [
                        'type' => 'TOTP',
                        'expires_in_seconds' => $mfaMinutes * 60,
                    ],
                ],
                'meta' => null,
            ]);
        }

        // Map failure statuses
        $httpStatus = match ($result->getStatus()) {
            AuthStatus::INVALID_CREDENTIALS => 401,
            AuthStatus::ACCOUNT_LOCKED => 423,
            AuthStatus::ACCOUNT_DISABLED, AuthStatus::ACCOUNT_EXPIRED => 403,
            default => 401,
        };

        $message = match ($result->getStatus()) {
            AuthStatus::INVALID_CREDENTIALS => 'Invalid username or password',
            AuthStatus::ACCOUNT_LOCKED => 'Account is temporarily locked or disabled',
            AuthStatus::ACCOUNT_DISABLED => 'Account is disabled',
            AuthStatus::ACCOUNT_EXPIRED => 'Account has expired',
            default => 'Authentication failed',
        };

        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => [
                'code' => $result->getErrorCode(),
                'fields' => new \stdClass,
            ],
        ], $httpStatus);
    }

    /**
     * Terminate the current authenticated session.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $sessionId = $request->session()->getId();

        if ($user && $sessionId) {
            UserSession::where('session_id', $sessionId)
                ->where('user_id', $user->id)
                ->whereNull('logout_at')
                ->update(['logout_at' => now()]);
        }

        Auth::guard('web')->logout();
        $this->authService->clearPendingMfaState($request->session());
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
            'data' => null,
            'meta' => null,
        ]);
    }

    /**
     * Retrieve the currently authenticated user's profile and roles.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => [
                'user' => new UserResource($user->load('roles')),
            ],
            'meta' => null,
        ]);
    }
}
