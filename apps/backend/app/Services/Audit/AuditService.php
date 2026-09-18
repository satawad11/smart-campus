<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;

/**
 * Centralized Audit Logging Service (AUTH-014).
 *
 * Records immutable audit entries for administrative and security-critical actions.
 * Enforces metadata sanitization and prevents secret leakage.
 */
class AuditService
{
    protected const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'password_hash',
        'secret',
        'mfa_secret',
        'mfa_secret_encrypted',
        'code',
        'otp',
        'totp',
        'token',
        'remember_token',
        'session_id',
        'csrf_token',
        '_token',
    ];

    /**
     * Record a security/administrative audit log entry.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        AuditAction|string $action,
        ?User $actor,
        ?string $subjectType,
        ?string $subjectUuid,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?AuditRequestContext $context = null,
        string $module = 'auth_rbac'
    ): AuditLog {
        $actionString = $action instanceof AuditAction ? $action->value : (string) $action;

        $cleanOldValues = $oldValues !== null ? $this->sanitizePayload($oldValues) : null;
        $cleanNewValues = $newValues !== null ? $this->sanitizePayload($newValues) : null;
        $cleanMetadata = $metadata !== null ? $this->sanitizePayload($metadata) : null;

        $cleanIp = $context?->ipAddress ? substr($context->ipAddress, 0, 45) : null;
        $cleanUserAgent = $context?->userAgent ? substr($context->userAgent, 0, 1000) : null;
        $cleanRequestId = $context?->requestId ? substr($context->requestId, 0, 100) : null;

        return AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $actionString,
            'module' => $module,
            'entity_type' => $subjectType,
            'entity_id' => $subjectUuid,
            'old_values' => $cleanOldValues,
            'new_values' => $cleanNewValues,
            'metadata' => $cleanMetadata,
            'ip_address' => $cleanIp,
            'user_agent' => $cleanUserAgent,
            'request_id' => $cleanRequestId,
        ]);
    }

    /**
     * Recursively strip sensitive parameters and credentials from audit payloads.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitizePayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, self::SENSITIVE_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
