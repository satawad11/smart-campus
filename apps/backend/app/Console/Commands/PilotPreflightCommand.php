<?php

namespace App\Console\Commands;

use App\Enums\DeviceStatus;
use App\Enums\PersonType;
use App\Enums\UserStatus;
use App\Models\AiNode;
use App\Models\BackupJob;
use App\Models\Camera;
use App\Models\Person;
use App\Models\Site;
use App\Models\User;
use App\Services\License\LicenseService;
use App\Services\License\LicenseState;
use App\Services\System\FeatureFlagService;
use App\Support\HealthChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * PILOT-001 / PILOT-005: read-only production environment preflight.
 *
 * Verifies the SEC-024 go-live conditions that software can check (locked
 * feature kill switches, production config, FACE-021 thresholds, service
 * health, pilot scope, verified baseline backup). It changes nothing.
 * Physical checks (cameras, AI node hardware, TLS, network) stay manual.
 */
class PilotPreflightCommand extends Command
{
    protected $signature = 'pilot:preflight
                            {--json : Output the check results as JSON}
                            {--require-backup : Fail (not warn) when no verified backup exists in the last 24 hours}';

    protected $description = 'Read-only pilot go-live preflight: feature locks, production config, thresholds, health, scope, backup';

    /** FACE-021 human-approved pilot thresholds (must never be self-tuned). */
    public const APPROVED_THRESHOLDS = [
        'face.recognition.match_threshold' => 0.80,
        'face.recognition.review_threshold' => 0.60,
        'face.recognition.ambiguity_margin' => 0.05,
        'face.duplicate_review.similarity_threshold' => 0.65,
        'face.liveness.threshold_real' => 0.70,
        'face.liveness.threshold_spoof' => 0.40,
    ];

    /** SEC-024 approved pilot scope. */
    public const PILOT_SCOPE = [
        'sites' => 1,
        'cameras' => 2,
        'ai_nodes' => 1,
        'students' => 80,
    ];

    /** Default/development secret values that must not reach the pilot host. */
    public const DEFAULT_SECRETS = ['', 'smart_campus', 'postgres', 'password', 'secret', 'minioadmin', 'changeme', 'campus-dev-root-secret', 'campus-dev-app-secret'];

    public const MIN_FREE_BYTES = 10 * 1073741824;

    /** Shared passwords set by development seeders (InitialAdminUserSeeder). Compared by hash only. */
    public const SHARED_DEV_PASSWORDS = ['Password123!'];

    /** Development accounts created by InitialAdminUserSeeder (@campus.local). */
    public const TEST_ACCOUNT_USERNAMES = ['superadmin', 'sysadmin', 'discipline', 'security', 'executive'];

    /** @var list<array{group: string, check: string, status: string, detail: string}> */
    private array $results = [];

    public function handle(FeatureFlagService $flags, HealthChecks $health): int
    {
        // The command instance is reused by repeated Artisan::call() invocations
        $this->results = [];

        $this->checkLockedFeatures($flags);
        $this->checkProductionConfig();
        $this->checkSecrets();
        $this->checkHost();
        $this->checkThresholds();
        $this->checkHealth($health);
        $this->checkScope();
        $this->checkBackup();
        $this->checkLicense(app(LicenseService::class));

        $failures = count(array_filter($this->results, fn ($r) => $r['status'] === 'FAIL'));
        $warnings = count(array_filter($this->results, fn ($r) => $r['status'] === 'WARN'));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'checked_at' => Carbon::now()->toIso8601String(),
                'result' => $failures === 0 ? 'PASS' : 'FAIL',
                'failures' => $failures,
                'warnings' => $warnings,
                'checks' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Group', 'Check', 'Status', 'Detail'], $this->results);
            $this->line(sprintf('Preflight %s: %d failure(s), %d warning(s).', $failures === 0 ? 'PASS' : 'FAIL', $failures, $warnings));
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function add(string $group, string $check, string $status, string $detail = ''): void
    {
        $this->results[] = ['group' => $group, 'check' => $check, 'status' => $status, 'detail' => $detail];
    }

    private function checkLockedFeatures(FeatureFlagService $flags): void
    {
        foreach (FeatureFlagService::LOCKED_V1_FEATURES as $feature) {
            $env = 'FEATURE_'.strtoupper($feature).'_ENABLED';

            $this->add('locked-features', $env.' explicitly false',
                $flags->isKillSwitchEngaged($feature) ? 'PASS' : 'FAIL',
                $flags->isKillSwitchEngaged($feature) ? 'kill switch engaged' : 'variable unset or not false');

            try {
                $enabled = $flags->isEnabled($feature);
                $this->add('locked-features', $feature.' effectively disabled', $enabled ? 'FAIL' : 'PASS',
                    $enabled ? 'feature is ENABLED' : 'fail-closed');
            } catch (Throwable $e) {
                $this->add('locked-features', $feature.' effectively disabled', 'FAIL', 'could not evaluate: '.class_basename($e));
            }
        }
    }

    private function checkProductionConfig(): void
    {
        $env = (string) config('app.env');
        $this->add('config', 'APP_ENV=production', $env === 'production' ? 'PASS' : 'FAIL', 'APP_ENV='.$env);
        $this->add('config', 'APP_DEBUG=false', config('app.debug') ? 'FAIL' : 'PASS');
        $this->add('config', 'APP_KEY set', filled(config('app.key')) ? 'PASS' : 'FAIL');

        $origins = (array) config('cors.allowed_origins');
        $badOrigin = collect($origins)->first(fn ($o) => $o === '*' || str_contains((string) $o, 'localhost') || str_contains((string) $o, '127.0.0.1'));
        $this->add('config', 'CORS origins explicit, non-local', $badOrigin === null ? 'PASS' : 'FAIL',
            $badOrigin === null ? count($origins).' origin(s)' : 'disallowed origin: '.$badOrigin);

        $this->add('config', 'SESSION_SECURE_COOKIE=true', config('session.secure') === true ? 'PASS' : 'FAIL');

        $connection = (string) config('database.default');
        $this->add('config', 'DB_CONNECTION=pgsql', $connection === 'pgsql' ? 'PASS' : 'FAIL', 'DB_CONNECTION='.$connection);

        $dbUser = (string) config('database.connections.pgsql.username');
        $dbUserStatus = match (true) {
            $dbUser === 'postgres' => 'FAIL',
            $dbUser === 'smart_campus' => 'WARN',
            default => 'PASS',
        };
        $this->add('config', 'DB user is least-privilege (SEC-005)', $dbUserStatus,
            $dbUserStatus === 'PASS' ? 'non-default application role' : 'DB_USERNAME='.$dbUser);

        $queue = (string) config('queue.default');
        $this->add('config', 'QUEUE_CONNECTION not sync', $queue !== 'sync' ? 'PASS' : 'FAIL', 'QUEUE_CONNECTION='.$queue);

        $logLevel = strtolower((string) config('logging.channels.'.config('logging.default').'.level', 'debug'));
        $this->add('config', 'log level not debug', $logLevel === 'debug' ? 'WARN' : 'PASS', 'level='.$logLevel);
    }

    /**
     * Known development/default secrets (docker-compose defaults, .env.example,
     * LEAST-PRIVILEGE-ROLES.sql placeholders). Values are compared, never printed.
     */
    private function checkSecrets(): void
    {
        $isDefault = fn (mixed $value): bool => in_array((string) $value, self::DEFAULT_SECRETS, true)
            || str_contains((string) $value, 'CHANGE_IN_PRODUCTION');

        $secrets = [
            'DB_PASSWORD' => config('database.connections.pgsql.password'),
            'AWS_SECRET_ACCESS_KEY' => config('filesystems.disks.s3.secret'),
            'AWS_ACCESS_KEY_ID' => config('filesystems.disks.s3.key'),
            'REVERB_APP_SECRET' => config('reverb.apps.apps.0.secret'),
        ];

        foreach ($secrets as $name => $value) {
            $this->add('secrets', $name.' not a default/dev value', $isDefault($value) ? 'FAIL' : 'PASS',
                $isDefault($value) ? 'default or placeholder value in use' : 'set');
        }

        $redisPassword = config('database.redis.default.password');
        $this->add('secrets', 'REDIS_PASSWORD set', filled($redisPassword) && ! $isDefault($redisPassword) ? 'PASS' : 'WARN',
            filled($redisPassword) ? 'set' : 'Redis without password: must be network-isolated');
    }

    private function checkHost(): void
    {
        try {
            $dbNow = Carbon::parse((string) DB::selectOne('select CURRENT_TIMESTAMP as now')->now);
            $drift = abs(Carbon::now()->diffInSeconds($dbNow, false));
            $this->add('host', 'app/database clock drift <= 5s', $drift <= 5 ? 'PASS' : 'FAIL',
                sprintf('%.1fs drift, app timezone %s', $drift, config('app.timezone')));
        } catch (Throwable $e) {
            $this->add('host', 'app/database clock drift <= 5s', 'FAIL', 'could not query: '.class_basename($e));
        }

        $free = @disk_free_space(storage_path());
        $this->add('host', 'free disk for storage/backups >= 10 GiB',
            $free !== false && $free >= self::MIN_FREE_BYTES ? 'PASS' : 'WARN',
            $free === false ? 'unknown' : sprintf('%.1f GiB free at storage/', $free / 1073741824));
    }

    private function checkThresholds(): void
    {
        foreach (self::APPROVED_THRESHOLDS as $key => $approved) {
            $actual = config($key);
            $ok = is_numeric($actual) && abs((float) $actual - $approved) < 0.00001;
            $this->add('thresholds', $key.' = '.number_format($approved, 2), $ok ? 'PASS' : 'FAIL',
                'configured '.(is_numeric($actual) ? number_format((float) $actual, 2) : 'unset'));
        }

        // V1 audit A-12: configuration that silently disables or fakes biometric
        // safety. FACE_LIVENESS_ENABLED=false makes every PAD check a bypass
        // REAL; the backend 'mock' liveness provider and the ai-service 'mock'
        // mode are not real recognition/PAD (PILOT-000 blocker B5).
        $this->add('biometrics', 'PAD (liveness) enabled', config('face.liveness.enabled') ? 'PASS' : 'FAIL',
            config('face.liveness.enabled') ? 'enabled' : 'FACE_LIVENESS_ENABLED=false bypasses PAD as REAL');
        $provider = (string) config('face.liveness.provider');
        $this->add('biometrics', 'PAD provider is not mock', $provider === 'mock' ? 'FAIL' : 'PASS', 'provider='.$provider);
        $this->add('biometrics', 'face recognition enabled', config('face.recognition.enabled') ? 'PASS' : 'FAIL');

        $mode = $this->aiServiceMode();
        $this->add('biometrics', 'AI service not in mock mode', $mode === null ? 'FAIL' : ($mode === 'mock' ? 'FAIL' : 'PASS'),
            $mode === null ? 'AI service mode unknown (unreachable)' : 'AI_MODE='.$mode.($mode === 'mock' ? ': no real recognition/PAD (blocker B5)' : ''));
    }

    private function aiServiceMode(): ?string
    {
        try {
            $response = Http::timeout(3)->get(rtrim((string) config('services.ai.url'), '/').'/version');

            return $response->successful() ? (string) $response->json('ai_mode') : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function checkHealth(HealthChecks $health): void
    {
        foreach (['database', 'redis', 'storage', 'aiService'] as $service) {
            try {
                $up = $health->{$service}();
            } catch (Throwable) {
                $up = false;
            }
            $this->add('health', $service, $up ? 'PASS' : 'FAIL', $up ? 'reachable' : 'unreachable');
        }
    }

    private function checkScope(): void
    {
        try {
            $counts = [
                'sites' => Site::query()->count(),
                'cameras' => Camera::query()->where('status', DeviceStatus::ACTIVE)->count(),
                'ai_nodes' => AiNode::query()->where('status', DeviceStatus::ACTIVE)->count(),
                'students' => Person::query()->where('person_type', PersonType::STUDENT)->count(),
            ];
        } catch (Throwable $e) {
            $this->add('scope', 'pilot scope', 'FAIL', 'could not query: '.class_basename($e));

            return;
        }

        foreach (self::PILOT_SCOPE as $item => $limit) {
            $this->add('scope', $item.' <= '.$limit, $counts[$item] <= $limit ? 'PASS' : 'WARN',
                $counts[$item].' configured'.($counts[$item] > $limit ? ' — expansion requires human approval' : ''));
        }

        $activeUsers = User::query()->where('status', UserStatus::ACTIVE)->count();
        $this->add('scope', 'active user accounts', 'INFO', $activeUsers.' active (pilot: 5 operators + administrators)');

        // InitialAdminUserSeeder creates development test accounts with a shared known
        // password; they must never be active on the pilot host.
        $testAccounts = User::query()
            ->where('status', UserStatus::ACTIVE)
            ->whereIn('username', self::TEST_ACCOUNT_USERNAMES)
            ->where('email', 'like', '%@campus.local')
            ->count();
        $this->add('config', 'no seeded test accounts active', $testAccounts === 0 ? 'PASS' : 'FAIL',
            $testAccounts === 0 ? 'none found' : $testAccounts.' InitialAdminUserSeeder account(s) active');

        // Catches renamed seeded accounts too: any active account still using a
        // known shared development password fails the preflight.
        $sharedPassword = User::query()
            ->where('status', UserStatus::ACTIVE)
            ->get(['id', 'password'])
            ->filter(fn (User $user) => collect(self::SHARED_DEV_PASSWORDS)
                ->contains(fn (string $known) => is_string($user->password) && Hash::check($known, $user->password)))
            ->count();
        $this->add('config', 'no active account uses a shared dev password', $sharedPassword === 0 ? 'PASS' : 'FAIL',
            $sharedPassword === 0 ? 'none found' : $sharedPassword.' active account(s) with a shared development password');
    }

    /**
     * COM-02. In enforce mode a missing/unusable license or missing ext-sodium
     * FAILs (growth would be refused). In warn/off mode (the pilot default)
     * this group only reports, so the approved pilot gate is unchanged.
     */
    private function checkLicense(LicenseService $licenses): void
    {
        $mode = $licenses->enforcementMode();
        $enforce = $mode === LicenseService::MODE_ENFORCE;
        $sodium = function_exists('sodium_crypto_sign_verify_detached');

        $this->add('license', 'ext-sodium available', $sodium ? 'PASS' : ($enforce ? 'FAIL' : 'WARN'),
            $sodium ? 'license signatures verifiable' : 'licenses cannot be verified');

        $state = $licenses->state();
        $detail = "mode={$mode}, state={$state->state}".($state->reasonCode ? " ({$state->reasonCode})" : '');
        $status = match (true) {
            $state->usable() => 'PASS',
            $enforce => 'FAIL',
            in_array($state->state, [LicenseState::INVALID, LicenseState::REVOKED], true) => 'WARN',
            default => 'PASS',
        };
        $this->add('license', 'license usable for enforcement mode', $status, $detail);

        if ($state->clockRollbackDetected) {
            $this->add('license', 'system clock not behind last validation', 'WARN', 'clock rollback detected: check NTP');
        }
    }

    private function checkBackup(): void
    {
        $status = $this->option('require-backup') ? 'FAIL' : 'WARN';

        try {
            foreach ([BackupJob::TYPE_POSTGRESQL, BackupJob::TYPE_STORAGE] as $type) {
                $latest = BackupJob::query()
                    ->where('type', $type)
                    ->where('status', BackupJob::STATUS_VERIFIED)
                    ->where('completed_at', '>=', Carbon::now()->subDay())
                    ->latest('completed_at')
                    ->first();

                if ($latest === null) {
                    $this->add('backup', 'verified '.$type.' backup in last 24h', $status, 'none found');

                    continue;
                }

                // The job row alone is not evidence: the file must still exist unchanged
                $intact = is_string($latest->target) && is_file($latest->target)
                    && hash_file($latest->checksum_algorithm ?: 'sha256', $latest->target) === $latest->checksum;
                $this->add('backup', 'verified '.$type.' backup in last 24h', $intact ? 'PASS' : $status,
                    'job '.$latest->id.' at '.$latest->completed_at?->toIso8601String().($intact ? '' : ' — backup file missing or checksum mismatch'));

                if (filled(config('backup.offhost_path'))) {
                    $offhost = $latest->metadata['offhost_file'] ?? null;
                    $copied = is_string($offhost) && is_file($offhost)
                        && hash_file($latest->checksum_algorithm ?: 'sha256', $offhost) === $latest->checksum;
                    $this->add('backup', $type.' backup copied off-host', $copied ? 'PASS' : $status,
                        $copied ? 'copy verified' : 'no verified off-host copy for the latest backup');
                }
            }
        } catch (Throwable $e) {
            $this->add('backup', 'verified backups', $status, 'could not query: '.class_basename($e));
        }

        $productionStatus = config('app.env') === 'production' ? 'FAIL' : 'WARN';

        $this->add('backup', 'backup schedule enabled', config('backup.schedule.enabled') ? 'PASS' : $productionStatus,
            config('backup.schedule.enabled')
                ? sprintf('db "%s", storage "%s"', config('backup.schedule.database_cron'), config('backup.schedule.storage_cron'))
                : 'BACKUP_SCHEDULE_ENABLED is not true');

        $offhostPath = config('backup.offhost_path');
        $offhostOk = filled($offhostPath) && is_dir($offhostPath) && is_writable($offhostPath);
        $this->add('backup', 'off-host backup target writable', $offhostOk ? 'PASS' : $productionStatus,
            blank($offhostPath) ? 'BACKUP_OFFHOST_PATH not set' : ($offhostOk ? 'writable' : 'not a writable directory'));
    }
}
