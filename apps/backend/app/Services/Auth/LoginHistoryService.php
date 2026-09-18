<?php

namespace App\Services\Auth;

use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Login History Recording Service (AUTH-013).
 *
 * Captures authentication attempts in the login_logs table.
 * Designed to be resilient: logging failures are caught and reported
 * internally without crashing the core authentication flow.
 */
class LoginHistoryService
{
    /**
     * Record a successful login attempt.
     */
    public function recordSuccess(User $user, string $ip, ?string $userAgent): ?LoginLog
    {
        return $this->writeLog(
            userId: $user->id,
            username: $user->username,
            success: true,
            failureReason: null,
            ipAddress: $ip,
            userAgent: $userAgent
        );
    }

    /**
     * Record a password-verified attempt that now requires MFA completion.
     */
    public function recordMfaRequired(User $user, string $ip, ?string $userAgent): ?LoginLog
    {
        return $this->writeLog(
            userId: $user->id,
            username: $user->username,
            success: false,
            failureReason: 'MFA_REQUIRED',
            ipAddress: $ip,
            userAgent: $userAgent
        );
    }

    /**
     * Record a failed login or MFA verification attempt.
     */
    public function recordFailure(string $username, string $failureReason, ?User $user, string $ip, ?string $userAgent): ?LoginLog
    {
        return $this->writeLog(
            userId: $user?->id,
            username: $username,
            success: false,
            failureReason: $failureReason,
            ipAddress: $ip,
            userAgent: $userAgent
        );
    }

    /**
     * Resilient write to login_logs.
     */
    protected function writeLog(
        ?int $userId,
        string $username,
        bool $success,
        ?string $failureReason,
        string $ipAddress,
        ?string $userAgent
    ): ?LoginLog {
        try {
            // Defensive sanitization: truncate to safe column limits
            $cleanIp = substr($ipAddress, 0, 45);
            $cleanUserAgent = substr($userAgent ?? 'unknown', 0, 1000);
            $cleanUsername = substr($username, 0, 255);

            return LoginLog::create([
                'user_id' => $userId,
                'username' => $cleanUsername,
                'success' => $success,
                'failure_reason' => $failureReason,
                'ip_address' => $cleanIp,
                'user_agent' => $cleanUserAgent,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to write login log entry', [
                'username' => $username,
                'success' => $success,
                'failure_reason' => $failureReason,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
