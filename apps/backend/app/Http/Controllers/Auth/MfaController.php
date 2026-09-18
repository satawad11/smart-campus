<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MfaConfirmRequest;
use App\Http\Requests\Auth\MfaDisableRequest;
use App\Http\Requests\Auth\MfaVerifyRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Audit\AuditRequestContext;
use App\Services\Auth\LoginHistoryService;
use App\Services\Auth\MfaService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use stdClass;

/**
 * MFA Controller (AUTH-007, AUTH-013).
 *
 * Handles HTTP TOTP setup, confirmation, login verification, and disabling.
 */
class MfaController extends Controller
{
    public function __construct(
        protected MfaService $mfaService,
        protected LoginHistoryService $loginHistoryService,
    ) {}

    /**
     * Start MFA setup for an authenticated user.
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $data = $this->mfaService->startSetup($user, $request->session());
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => [
                    'code' => 'AUTH_MFA_ALREADY_ENABLED',
                    'fields' => new stdClass,
                ],
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'MFA setup initialized',
            'data' => $data,
            'meta' => null,
        ]);
    }

    /**
     * Confirm MFA setup with the first valid TOTP code.
     */
    public function confirm(MfaConfirmRequest $request): JsonResponse
    {
        $user = $request->user();
        $code = (string) $request->validated('code');

        $success = $this->mfaService->confirmSetup(
            $user,
            $request->session(),
            $code,
            AuditRequestContext::fromRequest($request)
        );

        if (! $success) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid MFA confirmation code or expired setup',
                'error' => [
                    'code' => 'AUTH_MFA_INVALID_CODE',
                    'fields' => new stdClass,
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'MFA confirmed and enabled successfully',
            'data' => [
                'mfa_enabled' => true,
            ],
            'meta' => null,
        ]);
    }

    /**
     * Verify pending MFA login challenge and authenticate user.
     */
    public function verify(MfaVerifyRequest $request): JsonResponse
    {
        $session = $request->session();
        $pending = $session->get('auth.pending_mfa');
        $ip = $request->ip() ?? '127.0.0.1';
        $userAgent = $request->userAgent();

        if (! is_array($pending) || empty($pending['user_uuid']) || now()->timestamp > (int) ($pending['expires_at'] ?? 0)) {
            $pendingUser = is_array($pending) && ! empty($pending['user_uuid'])
                ? User::where('uuid', $pending['user_uuid'])->first()
                : null;

            if ($pendingUser) {
                $this->loginHistoryService->recordFailure($pendingUser->username, 'MFA_FAILED', $pendingUser, $ip, $userAgent);
            }

            $session->forget('auth.pending_mfa');

            return response()->json([
                'success' => false,
                'message' => 'MFA challenge expired or not found',
                'error' => [
                    'code' => 'AUTH_MFA_CHALLENGE_EXPIRED',
                    'fields' => new stdClass,
                ],
            ], 401);
        }

        $pendingUser = User::where('uuid', $pending['user_uuid'])->first();
        $code = (string) $request->validated('code');
        $user = $this->mfaService->verifyLoginChallenge($session, $code);

        if (! $user) {
            $username = $pendingUser?->username ?? 'unknown';
            $this->loginHistoryService->recordFailure($username, 'MFA_FAILED', $pendingUser, $ip, $userAgent);

            return response()->json([
                'success' => false,
                'message' => 'Invalid MFA verification code',
                'error' => [
                    'code' => 'AUTH_MFA_INVALID_CODE',
                    'fields' => new stdClass,
                ],
            ], 401);
        }

        // Record successful MFA verification in login_logs
        $this->loginHistoryService->recordSuccess($user, $ip, $userAgent);

        // 1. Establish authenticated web session and regenerate session ID
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        // 2. Create application-level user_sessions record keyed by session id.
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

    /**
     * Disable MFA for an authenticated user with password and TOTP verification.
     */
    public function disable(MfaDisableRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->mfa_enabled) {
            return response()->json([
                'success' => false,
                'message' => 'MFA is not enabled on this account',
                'error' => [
                    'code' => 'AUTH_MFA_NOT_ENABLED',
                    'fields' => new stdClass,
                ],
            ], 422);
        }

        $password = (string) $request->validated('password');
        $code = (string) $request->validated('code');

        $success = $this->mfaService->disableMfa(
            $user,
            $request->session(),
            $password,
            $code,
            AuditRequestContext::fromRequest($request)
        );

        if (! $success) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials or MFA verification code',
                'error' => [
                    'code' => 'AUTH_MFA_INVALID_CREDENTIALS',
                    'fields' => new stdClass,
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'MFA disabled successfully',
            'data' => [
                'mfa_enabled' => false,
            ],
            'meta' => null,
        ]);
    }
}
