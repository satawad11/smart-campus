<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-004 & SEC-005: Secrets strategy and least-privilege DB service user verification tests.
 */
class SecretsStrategyAndRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_least_privilege_sql_script_enforces_required_role_separation(): void
    {
        $sqlPath = base_path('../../docs/security/LEAST-PRIVILEGE-ROLES.sql');
        $this->assertFileExists($sqlPath, 'LEAST-PRIVILEGE-ROLES.sql must exist');

        $sql = file_get_contents($sqlPath);

        // Required 4 service accounts
        $this->assertStringContainsString('campus_migration_user', $sql);
        $this->assertStringContainsString('campus_app_user', $sql);
        $this->assertStringContainsString('campus_backup_user', $sql);
        $this->assertStringContainsString('campus_monitoring_user', $sql);

        // No superuser grants
        $this->assertStringNotContainsString('SUPERUSER', $sql, 'No service account may have SUPERUSER status');

        // app_user has DDL revoked
        $this->assertStringContainsString('REVOKE CREATE, DROP, TRUNCATE ON SCHEMA public FROM campus_app_user', $sql);

        // monitoring user has table data access revoked
        $this->assertStringContainsString('REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM campus_monitoring_user', $sql);

        // backup user has read-only SELECT
        $this->assertStringContainsString('GRANT SELECT ON ALL TABLES IN SCHEMA public TO campus_backup_user', $sql);
    }

    public function test_secrets_strategy_doc_defines_all_required_tiers(): void
    {
        $docPath = base_path('../../docs/security/SECRETS-STRATEGY.md');
        $this->assertFileExists($docPath, 'SECRETS-STRATEGY.md must exist');

        $doc = file_get_contents($docPath);

        $this->assertStringContainsString('Tier 1: Master Keys', $doc);
        $this->assertStringContainsString('Tier 2: Infrastructure Credentials', $doc);
        $this->assertStringContainsString('Tier 3: External Service Credentials', $doc);
        $this->assertStringContainsString('Tier 4: Node Credentials', $doc);
        $this->assertStringContainsString('Tier 5: RTSP & Camera Passwords', $doc);
        $this->assertStringContainsString('Fail-Closed Production Secrets Policy', $doc);
    }

    public function test_audit_service_redacts_sensitive_payloads(): void
    {
        $auditService = app(AuditService::class);

        $dirtyData = [
            'username' => 'operator1',
            'password' => 'secret_password_123',
            'token' => 'bearer_token_xyz',
            'rtsp_password' => 'camera_secret_999',
            'embedding' => [0.123, 0.456, 0.789],
        ];

        $log = $auditService->record(
            AuditAction::USER_UPDATED,
            null,
            'User',
            'usr-test-1',
            null,
            $dirtyData,
            ['api_key' => 'super_secret'],
            AuditRequestContext::empty(),
            'auth'
        );

        $this->assertInstanceOf(AuditLog::class, $log);
        $newValues = $log->new_values;

        $this->assertSame('operator1', $newValues['username']);
        $this->assertArrayNotHasKey('password', $newValues);
        $this->assertArrayNotHasKey('token', $newValues);
        $this->assertArrayNotHasKey('rtsp_password', $newValues);
        $this->assertArrayNotHasKey('embedding', $newValues);
        $this->assertArrayNotHasKey('api_key', $log->metadata);
    }
}
