<?php

namespace App\Services\License;

use App\Enums\AuditAction;
use App\Enums\DeviceStatus;
use App\Exceptions\ApiException;
use App\Exceptions\LicenseException;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\InstallationIdentity;
use App\Models\License;
use App\Models\LicenseRevocation;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Commercial license lifecycle and entitlement checks (COM-02).
 *
 * Guarantees:
 * - Offline: verification needs only the trusted public keys shipped with
 *   the release; no call to the vendor.
 * - Tamper-evident: the state is computed from the stored signed envelope,
 *   re-verified on every evaluation.
 * - Non-destructive: enforcement only refuses *growth* (see assertCanGrow).
 *   Nothing here deletes data or restricts reading, review, reports,
 *   exports, audit or health.
 */
class LicenseService
{
    public const RESOURCE_CAMERA = 'camera';

    public const RESOURCE_AI_NODE = 'ai_node';

    public const RESOURCE_FACE_ENROLLMENT = 'face_enrollment';

    public const MODE_OFF = 'off';

    public const MODE_WARN = 'warn';

    public const MODE_ENFORCE = 'enforce';

    public function __construct(
        private readonly LicenseVerifier $verifier,
        private readonly AuditService $audit,
    ) {}

    /**
     * The UUID a license must be issued for. Created once, on first use.
     */
    public function installationId(): string
    {
        $identity = InstallationIdentity::query()->orderBy('id')->first()
            ?? InstallationIdentity::create(['uuid' => (string) Str::uuid()]);

        return strtolower($identity->uuid);
    }

    public function enforcementMode(): string
    {
        $mode = strtolower((string) config('license.enforcement', self::MODE_WARN));

        // Fail safe to warn on a typo: never silently off, never a surprise block.
        return in_array($mode, [self::MODE_OFF, self::MODE_WARN, self::MODE_ENFORCE], true) ? $mode : self::MODE_WARN;
    }

    /**
     * Verify and install a license; the previous ACTIVE license becomes
     * SUPERSEDED (kept as history).
     *
     * @throws LicenseException
     */
    public function install(string $envelope, ?User $actor, ?AuditRequestContext $context = null): License
    {
        try {
            $verified = $this->verifier->verify($envelope, LicenseVerifier::TYPE_LICENSE);
            $terms = $this->verifier->normaliseLicense($verified['payload']);

            if ($terms['installation_id'] !== $this->installationId()) {
                throw LicenseException::rejected('LICENSE_INSTALLATION_MISMATCH', 'This license was issued for a different installation.');
            }
            if (LicenseRevocation::where('license_id', $terms['license_id'])->exists()) {
                throw LicenseException::rejected('LICENSE_REVOKED', 'This license has been revoked.');
            }
            $now = $this->effectiveNow(null);
            if ($now->greaterThan($terms['expires_at']->addDays($terms['grace_days']))) {
                throw LicenseException::rejected('LICENSE_EXPIRED', 'This license has already expired.');
            }

            $active = $this->activeLicense();
            if ($active !== null && $terms['issued_at']->lessThan($active->issued_at)) {
                // Replaying an older (e.g. larger) license over a renewal is refused.
                throw LicenseException::rejected('LICENSE_OLDER_THAN_ACTIVE', 'This license was issued before the license currently installed.');
            }
        } catch (LicenseException $e) {
            $this->audit->record(
                AuditAction::LICENSE_REJECTED, $actor, 'LICENSE', null,
                metadata: ['reason_code' => $e->errorCode],
                context: $context, module: 'license',
            );

            throw $e;
        }

        return DB::transaction(function () use ($envelope, $verified, $terms, $active, $actor, $context) {
            License::where('status', License::STATUS_ACTIVE)->update(['status' => License::STATUS_SUPERSEDED]);

            $license = License::create([
                'license_id' => $terms['license_id'],
                'key_id' => $verified['key_id'],
                'edition' => $terms['edition'],
                'plan' => $terms['plan'],
                'licensee_name' => $terms['licensee_name'],
                'installation_uuid' => $terms['installation_id'],
                'features' => $terms['features'],
                'limits' => $terms['limits'],
                'issued_at' => $terms['issued_at'],
                'not_before' => $terms['not_before'],
                'expires_at' => $terms['expires_at'],
                'grace_days' => $terms['grace_days'],
                'envelope' => trim($envelope),
                'status' => License::STATUS_ACTIVE,
                'installed_by_user_id' => $actor?->id,
                // Carry the clock high-water mark across renewals.
                'last_validated_at' => $this->maxTime($active?->last_validated_at, CarbonImmutable::now()),
            ]);

            $this->audit->record(
                AuditAction::LICENSE_INSTALLED, $actor, 'LICENSE', $license->uuid,
                oldValues: $active === null ? null : ['license_id' => $active->license_id, 'plan' => $active->plan, 'expires_at' => $active->expires_at->toIso8601String()],
                newValues: [
                    'license_id' => $terms['license_id'],
                    'key_id' => $verified['key_id'],
                    'edition' => $terms['edition'],
                    'plan' => $terms['plan'],
                    'features' => $terms['features'],
                    'limits' => $terms['limits'],
                    'expires_at' => $terms['expires_at']->toIso8601String(),
                    'grace_days' => $terms['grace_days'],
                ],
                context: $context, module: 'license',
            );

            return $license;
        });
    }

    /**
     * Import a signed revocation list. Revocations are permanent and lists
     * must arrive in increasing sequence (an older list can't be replayed).
     *
     * @return int number of newly revoked license ids
     *
     * @throws LicenseException
     */
    public function importRevocationList(string $envelope, ?User $actor, ?AuditRequestContext $context = null): int
    {
        $payload = $this->verifier->verify($envelope, LicenseVerifier::TYPE_REVOCATION_LIST)['payload'];

        $sequence = $payload['sequence'] ?? null;
        $ids = $payload['revoked_license_ids'] ?? null;
        if (! is_int($sequence) || $sequence < 1 || ! is_array($ids) || ! array_is_list($ids) || ! is_string($payload['issued_at'] ?? null)) {
            throw LicenseException::rejected('LICENSE_MALFORMED', 'Invalid revocation list payload.');
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw LicenseException::rejected('LICENSE_MALFORMED', 'Revocation list entries must be license UUIDs.');
            }
        }
        $issuedAt = CarbonImmutable::parse($payload['issued_at'])->utc();

        $stale = fn () => LicenseException::rejected('REVOCATION_LIST_STALE', 'This revocation list is not newer than the one already imported.');

        try {
            return $this->storeRevocationList($ids, $sequence, $issuedAt, $actor, $context, $stale);
        } catch (UniqueConstraintViolationException) {
            // A concurrent import of the same sequence won the unique index.
            throw $stale();
        }
    }

    /**
     * @param  list<string>  $ids
     */
    private function storeRevocationList(array $ids, int $sequence, CarbonImmutable $issuedAt, ?User $actor, ?AuditRequestContext $context, Closure $stale): int
    {
        return DB::transaction(function () use ($ids, $sequence, $issuedAt, $actor, $context, $stale) {
            $lastSequence = (int) DB::table('license_revocation_lists')->max('sequence');
            if ($sequence <= $lastSequence) {
                throw $stale();
            }

            $added = 0;
            foreach (array_unique(array_map('strtolower', $ids)) as $id) {
                $revocation = LicenseRevocation::firstOrCreate(
                    ['license_id' => $id],
                    ['list_sequence' => $sequence, 'list_issued_at' => $issuedAt],
                );
                $added += $revocation->wasRecentlyCreated ? 1 : 0;
            }

            DB::table('license_revocation_lists')->insert([
                'sequence' => $sequence,
                'issued_at' => $issuedAt,
                'entry_count' => count($ids),
                'newly_revoked_count' => $added,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                AuditAction::LICENSE_REVOCATIONS_IMPORTED, $actor, 'LICENSE_REVOCATION_LIST', null,
                newValues: ['sequence' => $sequence, 'entries' => count($ids), 'newly_revoked' => $added],
                context: $context, module: 'license',
            );

            return $added;
        });
    }

    public function state(): LicenseState
    {
        $installationId = $this->installationId();
        $active = $this->activeLicense();
        $now = $this->effectiveNow($active);
        $rollback = $this->clockRolledBack($active);

        if ($active === null) {
            return new LicenseState(LicenseState::NONE, $installationId, $now, clockRollbackDetected: $rollback);
        }

        try {
            $verified = $this->verifier->verify($active->envelope, LicenseVerifier::TYPE_LICENSE);
            $terms = $this->verifier->normaliseLicense($verified['payload']);
        } catch (LicenseException $e) {
            return new LicenseState(LicenseState::INVALID, $installationId, $now, $active, reasonCode: $e->errorCode, clockRollbackDetected: $rollback);
        }

        $make = fn (string $state, ?string $reason = null) => new LicenseState(
            $state, $installationId, $now, $active, $terms, $reason, $rollback,
        );

        if ($terms['installation_id'] !== $installationId) {
            return $make(LicenseState::INVALID, 'LICENSE_INSTALLATION_MISMATCH');
        }
        if (LicenseRevocation::where('license_id', $terms['license_id'])->exists()) {
            return $make(LicenseState::REVOKED, 'LICENSE_REVOKED');
        }
        if ($now->lessThan($terms['not_before'])) {
            return $make(LicenseState::NOT_YET_VALID, 'LICENSE_NOT_YET_VALID');
        }
        if ($now->lessThanOrEqualTo($terms['expires_at'])) {
            $warnFrom = $terms['expires_at']->subDays((int) config('license.expiry_warning_days', 30));

            return $make($now->greaterThanOrEqualTo($warnFrom) ? LicenseState::EXPIRING : LicenseState::VALID);
        }
        if ($now->lessThanOrEqualTo($terms['expires_at']->addDays($terms['grace_days']))) {
            return $make(LicenseState::GRACE, 'LICENSE_IN_GRACE_PERIOD');
        }

        return $make(LicenseState::EXPIRED, 'LICENSE_EXPIRED');
    }

    /**
     * Advance the clock high-water mark of the active license (license:check).
     */
    public function recordValidation(): void
    {
        $active = $this->activeLicense();
        if ($active !== null) {
            $active->update(['last_validated_at' => $this->maxTime($active->last_validated_at, CarbonImmutable::now())]);
        }
    }

    /**
     * Refuse growth the license doesn't cover. Call before the transaction
     * that adds the resource, so a refusal's audit record isn't rolled back
     * with it. (Two concurrent additions can overshoot a limit by one; the
     * hourly license:check then raises LICENSE_LIMIT_EXCEEDED.)
     *
     * - camera / ai_node: adding one more ACTIVE item must stay within the limit.
     * - face_enrollment: the license must include the `face` feature.
     * Any growth requires a usable license (VALID, EXPIRING or GRACE).
     *
     * Mode off: no-op. Mode warn: logged only. Mode enforce: ApiException 409.
     *
     * @throws ApiException
     */
    public function assertCanGrow(string $resource, ?User $actor = null, ?AuditRequestContext $context = null): void
    {
        $mode = $this->enforcementMode();
        if ($mode === self::MODE_OFF) {
            return;
        }

        $violation = $this->growthViolation($resource, $this->state());
        if ($violation === null) {
            return;
        }

        [$code, $message] = $violation;

        if ($mode === self::MODE_WARN) {
            Log::warning("License (warn mode) would refuse {$resource}: {$code}");

            return;
        }

        $this->audit->record(
            AuditAction::LICENSE_ENFORCEMENT_BLOCKED, $actor, 'LICENSE', null,
            metadata: ['resource' => $resource, 'reason_code' => $code],
            context: $context, module: 'license',
        );

        throw new ApiException($code, $message, 409);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public function growthViolation(string $resource, LicenseState $state): ?array
    {
        if (! $state->usable()) {
            return ['LICENSE_REQUIRED', "A valid license is required to add this resource (license state: {$state->state})."];
        }

        if ($resource === self::RESOURCE_FACE_ENROLLMENT) {
            return $state->hasFeature('face')
                ? null
                : ['LICENSE_FEATURE_NOT_ENTITLED', 'The installed license does not include face enrollment.'];
        }

        $limitName = match ($resource) {
            self::RESOURCE_CAMERA => 'max_cameras',
            self::RESOURCE_AI_NODE => 'max_ai_nodes',
            default => throw new \InvalidArgumentException("Unknown licensed resource: {$resource}"),
        };
        $limit = $state->limit($limitName);
        if ($limit !== null && $this->activeCount($resource) + 1 > $limit) {
            return ['LICENSE_LIMIT_REACHED', "The license allows {$limit} active ".($resource === self::RESOURCE_CAMERA ? 'camera(s)' : 'AI node(s)').'.'];
        }

        return null;
    }

    public function activeCount(string $resource): int
    {
        return match ($resource) {
            self::RESOURCE_CAMERA => Camera::where('status', DeviceStatus::ACTIVE)->count(),
            self::RESOURCE_AI_NODE => AiNode::where('status', DeviceStatus::ACTIVE)->count(),
            default => throw new \InvalidArgumentException("Unknown licensed resource: {$resource}"),
        };
    }

    private function activeLicense(): ?License
    {
        return License::where('status', License::STATUS_ACTIVE)->orderByDesc('id')->first();
    }

    /**
     * The later of the wall clock and the last validation time: setting the
     * clock back can't move a license away from its expiry.
     */
    private function effectiveNow(?License $active): CarbonImmutable
    {
        return $this->maxTime($active?->last_validated_at, CarbonImmutable::now());
    }

    private function clockRolledBack(?License $active): bool
    {
        if ($active?->last_validated_at === null) {
            return false;
        }
        $tolerance = (int) config('license.clock_rollback_tolerance_hours', 24);

        return CarbonImmutable::now()->lessThan($active->last_validated_at->subHours($tolerance));
    }

    private function maxTime(?CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return ($a !== null && $a->greaterThan($b)) ? $a : $b;
    }
}
