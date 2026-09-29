<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\BackupJob;
use App\Models\User;
use App\Services\System\FeatureFlagService;
use App\Services\System\SystemSettingService;
use App\Support\HealthChecks;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * PILOT-001 / PILOT-005: pilot:preflight must fail on any SEC-024 go-live
 * condition that software can verify, and pass on a compliant configuration.
 */
class PilotPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemSettingSeeder::class);

        $this->mock(HealthChecks::class, function ($mock) {
            $mock->shouldReceive('database', 'redis', 'storage', 'aiService')->andReturn(true);
        });
    }

    private function compliantConfig(): void
    {
        foreach (FeatureFlagService::LOCKED_V1_FEATURES as $feature) {
            config(['features.locked_kill_switches.'.$feature => false]);
        }

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'cors.allowed_origins' => ['https://pilot.campus.example'],
            'session.secure' => true,
            'database.connections.pgsql.username' => 'campus_app_user',
            'database.connections.pgsql.password' => 'pilot-db-'.bin2hex(random_bytes(8)),
            'filesystems.disks.s3.key' => 'pilot-access-key',
            'filesystems.disks.s3.secret' => 'pilot-s3-'.bin2hex(random_bytes(8)),
            'reverb.apps.apps.0.secret' => 'pilot-reverb-'.bin2hex(random_bytes(8)),
            'queue.default' => 'redis',
        ]);
    }

    /** @return array<string, mixed> */
    private function runPreflight(array $options = []): array
    {
        $exit = Artisan::call('pilot:preflight', ['--json' => true] + $options);
        $report = json_decode(Artisan::output(), true);
        $report['exit'] = $exit;

        return $report;
    }

    private function statusOf(array $report, string $check): ?string
    {
        foreach ($report['checks'] as $row) {
            if ($row['check'] === $check) {
                return $row['status'];
            }
        }

        return null;
    }

    public function test_default_test_environment_fails_preflight(): void
    {
        $report = $this->runPreflight();

        $this->assertSame(1, $report['exit']);
        $this->assertSame('FAIL', $report['result']);
        $this->assertSame('FAIL', $this->statusOf($report, 'FEATURE_DOOR_ENABLED explicitly false'));
        $this->assertSame('FAIL', $this->statusOf($report, 'APP_ENV=production'));
    }

    public function test_compliant_configuration_passes_and_thresholds_match_face_021(): void
    {
        $this->compliantConfig();
        $report = $this->runPreflight();

        // The default connection is never switched here: RefreshDatabase must roll back the
        // same connection it opened. On the sqlite suite only the pgsql check can fail.
        $expectedFailures = config('database.default') === 'pgsql' ? [] : ['DB_CONNECTION=pgsql'];
        $failed = array_values(array_filter($report['checks'], fn ($r) => $r['status'] === 'FAIL'));
        $this->assertSame($expectedFailures, array_column($failed, 'check'));
        $this->assertSame('PASS', $this->statusOf($report, 'face.recognition.match_threshold = 0.80'));
        $this->assertSame('PASS', $this->statusOf($report, 'face.liveness.threshold_spoof = 0.40'));
        $this->assertSame('PASS', $this->statusOf($report, 'door effectively disabled'));
    }

    public function test_enabled_locked_feature_or_changed_threshold_fails(): void
    {
        $this->compliantConfig();
        config([
            'features.locked_kill_switches.door' => null,
            'face.recognition.match_threshold' => 0.75,
        ]);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.door' => true]);

        $report = $this->runPreflight();

        $this->assertSame(1, $report['exit']);
        $this->assertSame('FAIL', $this->statusOf($report, 'door effectively disabled'));
        $this->assertSame('FAIL', $this->statusOf($report, 'face.recognition.match_threshold = 0.80'));
    }

    public function test_backup_check_warns_by_default_and_fails_when_required(): void
    {
        $this->assertSame('WARN', $this->statusOf($this->runPreflight(), 'verified POSTGRESQL backup in last 24h'));
        $this->assertSame('FAIL', $this->statusOf($this->runPreflight(['--require-backup' => true]), 'verified POSTGRESQL backup in last 24h'));

        $file = storage_path('app/backups/test/preflight_dump.sql');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, "--\n-- PostgreSQL database dump\n--\n");

        BackupJob::create([
            'type' => BackupJob::TYPE_POSTGRESQL,
            'status' => BackupJob::STATUS_VERIFIED,
            'target' => $file,
            'checksum' => hash_file('sha256', $file),
            'checksum_algorithm' => 'sha256',
            'completed_at' => Carbon::now(),
        ]);

        $this->assertSame('PASS', $this->statusOf($this->runPreflight(['--require-backup' => true]), 'verified POSTGRESQL backup in last 24h'));

        // A job row whose file was lost or altered is not a backup
        File::append($file, 'tampered');
        $this->assertSame('FAIL', $this->statusOf($this->runPreflight(['--require-backup' => true]), 'verified POSTGRESQL backup in last 24h'));
        File::delete($file);
        $this->assertSame('FAIL', $this->statusOf($this->runPreflight(['--require-backup' => true]), 'verified POSTGRESQL backup in last 24h'));
    }

    public function test_default_or_placeholder_secrets_fail_without_being_printed(): void
    {
        $this->compliantConfig();
        config([
            'database.connections.pgsql.password' => 'CHANGE_IN_PRODUCTION_APP_PWD',
            'filesystems.disks.s3.secret' => 'minioadmin',
        ]);

        $exit = Artisan::call('pilot:preflight', ['--json' => true]);
        $output = Artisan::output();
        $report = json_decode($output, true);

        $this->assertSame(1, $exit);
        $this->assertSame('FAIL', $this->statusOf($report, 'DB_PASSWORD not a default/dev value'));
        $this->assertSame('FAIL', $this->statusOf($report, 'AWS_SECRET_ACCESS_KEY not a default/dev value'));
        $this->assertSame('PASS', $this->statusOf($report, 'REVERB_APP_SECRET not a default/dev value'));
        $this->assertStringNotContainsString('CHANGE_IN_PRODUCTION_APP_PWD', $output);
        $this->assertStringNotContainsString('minioadmin', $output);
    }

    public function test_host_checks_report_clock_drift_and_disk(): void
    {
        $report = $this->runPreflight();

        $this->assertSame('PASS', $this->statusOf($report, 'app/database clock drift <= 5s'));
        $this->assertContains($this->statusOf($report, 'free disk for storage/backups >= 10 GiB'), ['PASS', 'WARN']);
    }

    public function test_active_seeded_test_account_fails_preflight(): void
    {
        $this->assertSame('PASS', $this->statusOf($this->runPreflight(), 'no seeded test accounts active'));

        User::factory()->create([
            'username' => 'superadmin',
            'email' => 'superadmin@campus.local',
            'status' => UserStatus::ACTIVE,
        ]);

        $this->assertSame('FAIL', $this->statusOf($this->runPreflight(), 'no seeded test accounts active'));
    }

    public function test_preflight_does_not_modify_feature_settings(): void
    {
        $before = app(FeatureFlagService::class)->getAll();
        $this->runPreflight();

        $this->assertSame($before, app(FeatureFlagService::class)->getAll());
        $this->assertFalse(app(FeatureFlagService::class)->isEnabled('door'));
    }
}
