<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\UserSession;
use App\Services\Audit\AuditRequestContext;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\LoginHistoryService;
use App\Services\Auth\PasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Authentication Controller (AUTH-005, AUTH-013, AUTH-015).
 *
 * Handles HTTP login, logout, password change, and authenticated user profile endpoints.
 * Session state is managed via Laravel web guard session cookies.
 */
class AuthController extends Controller
{
    public function __construct(
        protected AuthenticationService $authService,
        protected LoginHistoryService $loginHistoryService,
        protected PasswordService $passwordService,
    ) {}

    /**
     * Authenticate credentials and establish a user session.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $username = (string) $request->validated('username');
        $password = (string) $request->validated('password');
        $ip = $request->ip() ?? '127.0.0.1';
        $userAgent = $request->userAgent();

        $result = $this->authService->authenticate(
            $username,
            $password
        );

        if ($result->isSuccessful()) {
            $user = $result->getUser();

            // Record successful login in login_logs
            $this->loginHistoryService->recordSuccess($user, $ip, $userAgent);

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
                'ip_address' => $ip,
                'user_agent' => $userAgent ?? 'unknown',
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

            // Record password stage success (MFA required) in login_logs
            $this->loginHistoryService->recordMfaRequired($user, $ip, $userAgent);

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
        $reason = match ($result->getStatus()) {
            AuthStatus::INVALID_CREDENTIALS => 'INVALID_CREDENTIALS',
            AuthStatus::ACCOUNT_LOCKED => 'ACCOUNT_LOCKED',
            AuthStatus::ACCOUNT_DISABLED => 'ACCOUNT_DISABLED',
            AuthStatus::ACCOUNT_EXPIRED => 'ACCOUNT_EXPIRED',
            default => 'AUTHENTICATION_FAILED',
        };

        // Record failed attempt in login_logs
        $this->loginHistoryService->recordFailure($username, $reason, $result->getUser(), $ip, $userAgent);

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

    /**
     * Change the authenticated user's own password.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $currentPassword = (string) $request->validated('current_password');
        $newPassword = (string) $request->validated('password');
        $currentSessionId = $request->session()->getId();

        $updated = $this->passwordService->changePassword(
            $user,
            $currentPassword,
            $newPassword,
            $currentSessionId,
            AuditRequestContext::fromRequest($request)
        );

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully',
            'data' => [
                'user' => new UserResource($updated->load('roles')),
            ],
            'meta' => null,
        ]);
    }
}
