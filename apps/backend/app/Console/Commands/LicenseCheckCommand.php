<?php

namespace App\Console\Commands;

use App\Models\SystemAlert;
use App\Services\License\LicenseService;
use App\Services\License\LicenseState;
use App\Services\System\AlertLifecycleService;
use Illuminate\Console\Command;

/**
 * Hourly license check (COM-02 / COM-05): advances the clock high-water mark
 * and raises one unresolved alert per condition. Nothing is blocked here.
 *
 * Mode off: no alerts. A missing license alerts only in enforce mode, so the
 * pilot (warn mode, no license yet) gains no new alert noise.
 */
class LicenseCheckCommand extends Command
{
    protected $signature = 'license:check';

    protected $description = 'Record a license validation and raise license expiry/limit alerts';

    public function handle(LicenseService $licenses, AlertLifecycleService $alerts): int
    {
        $licenses->recordValidation();
        $state = $licenses->state();
        $mode = $licenses->enforcementMode();

        if ($mode === LicenseService::MODE_OFF) {
            $this->info("license:check state {$state->state} (enforcement off, no alerts).");

            return self::SUCCESS;
        }

        $source = 'license:'.($state->terms['license_id'] ?? 'none');
        $raised = 0;
        $raise = function (string $type, string $severity, string $title, string $message) use ($alerts, $source, $state, &$raised) {
            $raised += $alerts->triggerOnce($type, $severity, $title, $message, $source, ['state' => $state->state]) ? 1 : 0;
        };

        $expiresAt = $state->terms['expires_at'] ?? null;
        match ($state->state) {
            LicenseState::EXPIRING => $raise('LICENSE_EXPIRING', SystemAlert::SEVERITY_WARNING,
                'License expires soon', 'The license expires at '.$expiresAt?->toIso8601String().'. Request a renewal.'),
            LicenseState::GRACE => $raise('LICENSE_GRACE_PERIOD', SystemAlert::SEVERITY_CRITICAL,
                'License expired: grace period', 'The license expired; the grace period ends at '.$state->graceEndsAt()?->toIso8601String().'. Growth will then be refused in enforce mode.'),
            LicenseState::EXPIRED => $raise('LICENSE_EXPIRED', SystemAlert::SEVERITY_CRITICAL,
                'License expired', 'The license and its grace period have ended. Existing data and monitoring keep working; adding cameras, AI nodes or enrollments is refused in enforce mode.'),
            LicenseState::REVOKED, LicenseState::INVALID => $raise('LICENSE_INVALID', SystemAlert::SEVERITY_CRITICAL,
                'License not valid', "The installed license is {$state->state} ({$state->reasonCode}). Contact the vendor."),
            LicenseState::NONE => $mode === LicenseService::MODE_ENFORCE
                ? $raise('LICENSE_MISSING', SystemAlert::SEVERITY_WARNING, 'No license installed', 'Install a license to add cameras, AI nodes or enrollments.')
                : null,
            default => null,
        };

        foreach ([LicenseService::RESOURCE_CAMERA => 'max_cameras', LicenseService::RESOURCE_AI_NODE => 'max_ai_nodes'] as $resource => $limitName) {
            $limit = $state->limit($limitName);
            $active = $licenses->activeCount($resource);
            if ($limit !== null && $active > $limit) {
                $raise('LICENSE_LIMIT_EXCEEDED', SystemAlert::SEVERITY_WARNING, 'License limit exceeded',
                    "{$active} active {$resource} record(s) exceed the licensed {$limitName} of {$limit}.");
            }
        }

        if ($state->clockRollbackDetected) {
            $raise('LICENSE_CLOCK_ROLLBACK', SystemAlert::SEVERITY_WARNING, 'System clock moved backwards',
                'The system clock is behind the last license validation. Check NTP; expiry is evaluated against the later time.');
        }

        $this->info("license:check state {$state->state}, raised {$raised} new alert(s).");

        return self::SUCCESS;
    }
}
