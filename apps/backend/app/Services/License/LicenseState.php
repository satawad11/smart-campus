<?php

namespace App\Services\License;

use App\Models\License;
use Carbon\CarbonImmutable;

/**
 * The evaluated license state of this installation at one moment (COM-02).
 * Built only from a freshly re-verified signed payload, never from the
 * denormalised license columns.
 */
final class LicenseState
{
    public const NONE = 'NONE';

    public const INVALID = 'INVALID';

    public const REVOKED = 'REVOKED';

    public const NOT_YET_VALID = 'NOT_YET_VALID';

    public const VALID = 'VALID';

    public const EXPIRING = 'EXPIRING';

    public const GRACE = 'GRACE';

    public const EXPIRED = 'EXPIRED';

    /** States in which the installation may grow within its limits. */
    private const USABLE = [self::VALID, self::EXPIRING, self::GRACE];

    /**
     * @param  array<string, mixed>|null  $terms  normalised payload (LicenseVerifier::normaliseLicense)
     */
    public function __construct(
        public readonly string $state,
        public readonly string $installationId,
        public readonly CarbonImmutable $evaluatedAt,
        public readonly ?License $license = null,
        public readonly ?array $terms = null,
        public readonly ?string $reasonCode = null,
        public readonly bool $clockRollbackDetected = false,
    ) {}

    public function usable(): bool
    {
        return in_array($this->state, self::USABLE, true);
    }

    /** Null means unlimited (or no license terms to read a limit from). */
    public function limit(string $name): ?int
    {
        return $this->terms['limits'][$name] ?? null;
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->terms['features'] ?? [], true);
    }

    public function graceEndsAt(): ?CarbonImmutable
    {
        return $this->terms === null ? null : $this->terms['expires_at']->addDays($this->terms['grace_days']);
    }

    /**
     * API/CLI representation. Never contains the signed envelope.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $terms = $this->terms;

        return [
            'state' => $this->state,
            'usable' => $this->usable(),
            'reason_code' => $this->reasonCode,
            'installation_id' => $this->installationId,
            'evaluated_at' => $this->evaluatedAt->toIso8601String(),
            'clock_rollback_detected' => $this->clockRollbackDetected,
            'enforcement' => (string) config('license.enforcement'),
            'license' => $terms === null ? null : [
                'license_id' => $terms['license_id'],
                'licensee_name' => $terms['licensee_name'],
                'edition' => $terms['edition'],
                'plan' => $terms['plan'],
                'features' => $terms['features'],
                'limits' => $terms['limits'],
                'issued_at' => $terms['issued_at']->toIso8601String(),
                'not_before' => $terms['not_before']->toIso8601String(),
                'expires_at' => $terms['expires_at']->toIso8601String(),
                'grace_days' => $terms['grace_days'],
                'grace_ends_at' => $this->graceEndsAt()?->toIso8601String(),
                'days_until_expiry' => (int) floor($this->evaluatedAt->diffInDays($terms['expires_at'], false)),
                'installed_at' => $this->license?->created_at?->toIso8601String(),
            ],
        ];
    }
}
