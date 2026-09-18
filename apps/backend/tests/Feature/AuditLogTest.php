<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserSession;
use App\Services\Audit\AuditService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function assignRole(User $user, string $roleCode): void
    {
        $role = Role::where('code', $roleCode)->firstOrFail();
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);
    }

    private function createAuditor(): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->assignRole($user, 'SUPER_ADMIN'); // Has audit.view, user.create, user.update, role.manage, etc.

        return $user;
    }

    // ==========================================
    // 1. USER MANAGEMENT AUDIT RECORDING TESTS
    // ==========================================

    public function test_user_creation_records_user_created_audit_log(): void
    {
        $admin = $this->createAuditor();

        $response = $this->actingAs($admin, 'web')->postJson('/api/v1/users', [
            'username' => 'new_user_1',
            'email' => 'new1@example.com',
            'password' => 'Password123!',
            'status' => 'ACTIVE',
        ]);

        $response->assertStatus(201);
        $createdUuid = $response->json('data.uuid');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::USER_CREATED->value,
            'module' => 'auth_rbac',
            'entity_type' => 'USER',
            'entity_id' => $createdUuid,
            'user_id' => $admin->id,
        ]);

        $log = AuditLog::where('action', AuditAction::USER_CREATED->value)->first();
        $this->assertNotNull($log);
        $this->assertEquals('new_user_1', $log->new_values['username']);
        $this->assertEquals('new1@example.com', $log->new_values['email']);
        $this->assertArrayNotHasKey('password', $log->new_values);
    }

    public function test_user_update_records_user_updated_audit_log_only_for_modified_fields(): void
    {
        $admin = $this->createAuditor();
        $target = User::factory()->create(['username' => 'before_name', 'email' => 'before@example.com', 'status' => UserStatus::ACTIVE]);

        $response = $this->actingAs($admin, 'web')->putJson("/api/v1/users/{$target->uuid}", [
            'username' => 'after_name',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::USER_UPDATED->value,
            'entity_type' => 'USER',
            'entity_id' => $target->uuid,
            'user_id' => $admin->id,
        ]);

        $log = AuditLog::where('action', AuditAction::USER_UPDATED->value)->first();
        $this->assertNotNull($log);
        $this->assertEquals(['username' => 'before_name'], $log->old_values);
        $this->assertEquals(['username' => 'after_name'], $log->new_values);
    }

    public function test_user_status_change_records_user_status_changed_audit_log(): void
    {
        $admin = $this->createAuditor();
        $target = User::factory()->create(['username' => 'target_user', 'status' => UserStatus::ACTIVE]);

        // Create a session for target to test revocation count
        UserSession::create([
            'user_id' => $target->id,
            'session_id' => 'session_to_revoke',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
            'login_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'web')->patchJson("/api/v1/users/{$target->uuid}/status", [
            'status' => 'DISABLED',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::USER_STATUS_CHANGED->value,
            'entity_type' => 'USER',
            'entity_id' => $target->uuid,
            'user_id' => $admin->id,
        ]);

        $log = AuditLog::where('action', AuditAction::USER_STATUS_CHANGED->value)->first();
        $this->assertNotNull($log);
        $this->assertEquals(['status' => 'ACTIVE'], $log->old_values);
        $this->assertEquals(['status' => 'DISABLED', 'revoked_sessions_count' => 1], $log->new_values);
    }

    public function test_user_password_reset_records_user_password_reset_audit_log(): void
    {
        $admin = $this->createAuditor();
        $target = User::factory()->create(['username' => 'reset_target', 'status' => UserStatus::ACTIVE]);

        $response = $this->actingAs($admin, 'web')->postJson("/api/v1/users/{$target->uuid}/reset-password", [
            'password' => 'BrandNewPassword123!',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::USER_PASSWORD_RESET->value,
            'entity_type' => 'USER',
            'entity_id' => $target->uuid,
            'user_id' => $admin->id,
        ]);

        $log = AuditLog::where('action', AuditAction::USER_PASSWORD_RESET->value)->first();
        $this->assertNotNull($log);
        $this->assertEquals(['must_change_password' => true, 'revoked_sessions_count' => 0], $log->new_values);
        // Sensitive password must not be present anywhere
        $this->assertStringNotContainsString('BrandNewPassword123!', json_encode($log->toArray()));
    }

    // ==========================================
    // 2. MFA AUDIT RECORDING TESTS
    // ==========================================

    public function test_mfa_confirmation_records_mfa_enabled_audit_log(): void
    {
        $user = User::factory()->create(['username' => 'mfa_user', 'status' => UserStatus::ACTIVE, 'mfa_enabled' => false]);
        $secret = 'JBSWY3DPEHPK3PXP';

        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->actingAs($user, 'web')
            ->withSession([
                'auth.pending_mfa_setup' => [
                    'user_uuid' => $user->uuid,
                    'secret_encrypted' => Crypt::encryptString($secret),
                    'issued_at' => now()->timestamp,
                    'expires_at' => now()->addMinutes(10)->timestamp,
                ],
            ])
            ->postJson('/api/v1/auth/mfa/confirm', ['code' => $code])
            ->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::MFA_ENABLED->value,
            'entity_type' => 'USER',
            'entity_id' => $user->uuid,
            'user_id' => $user->id,
        ]);

        $log = AuditLog::where('action', AuditAction::MFA_ENABLED->value)->first();
        $this->assertNotNull($log);
        $this->assertEquals(['mfa_enabled' => true], $log->new_values);
        $this->assertStringNotContainsString($secret, json_encode($log->toArray()));
    }

    public function test_mfa_disable_records_mfa_disabled_audit_log(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = User::factory()->create([
            'username' => 'mfa_disable_user',
            'password' => Hash::make('CorrectPassword123!'),
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
            'mfa_secret_encrypted' => Crypt::encryptString($secret),
        ]);

        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/auth/mfa/disable', [
                'password' => 'CorrectPassword123!',
                'code' => $code,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::MFA_DISABLED->value,
            'entity_type' => 'USER',
            'entity_id' => $user->uuid,
            'user_id' => $user->id,
        ]);

        $log = AuditLog::where('action', AuditAction::MFA_DISABLED->value)->first();
        $this->assertNotNull($log);
        $this->assertEquals(['mfa_enabled' => false], $log->new_values);
    }

    // ==========================================
    // 3. ROLE MANAGEMENT AUDIT RECORDING TESTS
    // ==========================================

    public function test_role_creation_records_role_created_audit_log(): void
    {
        $admin = $this->createAuditor();

        $response = $this->actingAs($admin, 'web')->postJson('/api/v1/roles', [
            'code' => 'AUDIT_OFFICER',
            'name' => 'Audit Officer',
            'description' => 'Security auditor role',
            'status' => 'ACTIVE',
        ]);

        $response->assertStatus(201);
        $roleUuid = $response->json('data.uuid');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ROLE_CREATED->value,
            'entity_type' => 'ROLE',
            'entity_id' => $roleUuid,
            'user_id' => $admin->id,
        ]);
    }

    public function test_role_update_records_role_updated_audit_log(): void
    {
        $admin = $this->createAuditor();
        $role = Role::factory()->create([
            'code' => 'EDITABLE_ROLE',
            'name' => 'Old Name',
            'status' => RoleStatus::ACTIVE,
            'system_role' => false,
        ]);

        $response = $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$role->uuid}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ROLE_UPDATED->value,
            'entity_type' => 'ROLE',
            'entity_id' => $role->uuid,
            'user_id' => $admin->id,
        ]);

        $log = AuditLog::where('action', AuditAction::ROLE_UPDATED->value)->first();
        $this->assertEquals(['name' => 'Old Name'], $log->old_values);
        $this->assertEquals(['name' => 'New Name'], $log->new_values);
    }

    public function test_role_permission_matrix_update_records_audit_log_with_added_and_removed(): void
    {
        $admin = $this->createAuditor();
        $role = Role::factory()->create([
            'code' => 'MATRIX_ROLE',
            'name' => 'Matrix Role',
            'status' => RoleStatus::ACTIVE,
            'system_role' => false,
        ]);

        $perm1 = Permission::where('code', 'user.view')->firstOrFail();
        $role->permissions()->attach($perm1->id);

        $response = $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$role->uuid}/permissions", [
            'permissions' => ['user.create', 'user.update'],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ROLE_PERMISSION_MATRIX_UPDATED->value,
            'entity_type' => 'ROLE',
            'entity_id' => $role->uuid,
            'user_id' => $admin->id,
        ]);

        $log = AuditLog::where('action', AuditAction::ROLE_PERMISSION_MATRIX_UPDATED->value)->first();
        $this->assertNotNull($log);
        $this->assertEqualsCanonicalizing(['user.create', 'user.update'], $log->new_values['added']);
        $this->assertEqualsCanonicalizing(['user.view'], $log->new_values['removed']);
    }

    // ==========================================
    // 4. SESSION REVOCATION AUDIT RECORDING TESTS
    // ==========================================

    public function test_session_revocation_records_session_revoked_audit_log(): void
    {
        $admin = $this->createAuditor();
        $target = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $session = UserSession::create([
            'user_id' => $target->id,
            'session_id' => 'session_123',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
            'login_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'web')->deleteJson("/api/v1/users/{$target->uuid}/sessions/{$session->uuid}");
        $response->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::SESSION_REVOKED->value,
            'entity_type' => 'SESSION',
            'entity_id' => $session->uuid,
            'user_id' => $admin->id,
        ]);
    }

    public function test_user_sessions_revoke_all_records_user_sessions_revoked_audit_log(): void
    {
        $admin = $this->createAuditor();
        $target = User::factory()->create(['status' => UserStatus::ACTIVE]);

        UserSession::create(['user_id' => $target->id, 'session_id' => 's1', 'ip_address' => '127.0.0.1', 'user_agent' => 'Test', 'login_at' => now(), 'last_activity_at' => now()]);
        UserSession::create(['user_id' => $target->id, 'session_id' => 's2', 'ip_address' => '127.0.0.1', 'user_agent' => 'Test', 'login_at' => now(), 'last_activity_at' => now()]);

        $response = $this->actingAs($admin, 'web')->postJson("/api/v1/users/{$target->uuid}/sessions/revoke-all");
        $response->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::USER_SESSIONS_REVOKED->value,
            'entity_type' => 'USER',
            'entity_id' => $target->uuid,
            'user_id' => $admin->id,
        ]);

        $log = AuditLog::where('action', AuditAction::USER_SESSIONS_REVOKED->value)->first();
        $this->assertEquals(['revoked_count' => 2], $log->new_values);
    }

    public function test_revoke_other_sessions_records_other_sessions_revoked_audit_log(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $currentSessionId = 'current_sess';
        UserSession::create(['user_id' => $user->id, 'session_id' => $currentSessionId, 'ip_address' => '127.0.0.1', 'user_agent' => 'Test', 'login_at' => now(), 'last_activity_at' => now()]);
        UserSession::create(['user_id' => $user->id, 'session_id' => 'other_sess', 'ip_address' => '127.0.0.1', 'user_agent' => 'Test', 'login_at' => now(), 'last_activity_at' => now()]);

        $this->actingAs($user, 'web')
            ->withSession(['_token' => 'dummy'])
            ->postJson('/api/v1/auth/sessions/revoke-others')
            ->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::OTHER_SESSIONS_REVOKED->value,
            'entity_type' => 'USER',
            'entity_id' => $user->uuid,
            'user_id' => $user->id,
        ]);
    }

    // ==========================================
    // 5. TRANSACTIONAL ROLLBACK TEST
    // ==========================================

    public function test_audit_log_rolls_back_if_parent_transaction_fails(): void
    {
        $admin = $this->createAuditor();
        $auditService = app(AuditService::class);

        try {
            DB::transaction(function () use ($admin, $auditService) {
                $auditService->record(
                    AuditAction::USER_CREATED,
                    $admin,
                    'USER',
                    'dummy-uuid',
                    newValues: ['username' => 'trans_user']
                );

                throw new \RuntimeException('Database failure simulation');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        $this->assertDatabaseMissing('audit_logs', [
            'action' => AuditAction::USER_CREATED->value,
            'entity_id' => 'dummy-uuid',
        ]);
    }

    // ==========================================
    // 6. SENSITIVE SECRET OMISSION TEST
    // ==========================================

    public function test_sensitive_credentials_are_automatically_sanitized_from_audit_logs(): void
    {
        $admin = $this->createAuditor();
        $auditService = app(AuditService::class);

        $auditService->record(
            AuditAction::USER_UPDATED,
            $admin,
            'USER',
            'test-entity-uuid',
            oldValues: [
                'username' => 'old_name',
                'password' => 'PlaintextPassword123!',
                'password_hash' => '$2y$12$e8293hf9823h...',
            ],
            newValues: [
                'username' => 'new_name',
                'secret' => 'TOTPSECRET123456',
                'mfa_secret_encrypted' => 'encrypted_base64_string',
                'token' => 'session_bearer_token',
                'nested' => [
                    'otp' => '123456',
                    'safe_key' => 'safe_value',
                ],
            ],
            metadata: [
                'session_id' => 'active_session_hash',
                'action_origin' => 'admin_panel',
            ]
        );

        $log = AuditLog::where('entity_id', 'test-entity-uuid')->first();
        $this->assertNotNull($log);

        $serialized = json_encode($log->toArray());
        $this->assertStringNotContainsString('PlaintextPassword123!', $serialized);
        $this->assertStringNotContainsString('$2y$12$e8293hf9823h...', $serialized);
        $this->assertStringNotContainsString('TOTPSECRET123456', $serialized);
        $this->assertStringNotContainsString('encrypted_base64_string', $serialized);
        $this->assertStringNotContainsString('session_bearer_token', $serialized);
        $this->assertStringNotContainsString('123456', $serialized);
        $this->assertStringNotContainsString('active_session_hash', $serialized);

        $this->assertEquals('new_name', $log->new_values['username']);
        $this->assertEquals('safe_value', $log->new_values['nested']['safe_key']);
        $this->assertEquals('admin_panel', $log->metadata['action_origin']);
    }

    // ==========================================
    // 7. READ API & AUTHORIZATION TESTS
    // ==========================================

    public function test_unauthenticated_audit_log_query_is_401(): void
    {
        $this->getJson('/api/v1/audit-logs')->assertStatus(401);
    }

    public function test_audit_log_query_without_permission_is_403(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->assignRole($user, 'SECURITY_OFFICER'); // Has no audit.view permission

        $this->actingAs($user, 'web')->getJson('/api/v1/audit-logs')->assertStatus(403);
    }

    public function test_permitted_user_can_list_and_filter_audit_logs(): void
    {
        $admin = $this->createAuditor();

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => AuditAction::USER_CREATED->value,
            'module' => 'AUTH',
            'entity_type' => 'USER',
            'entity_id' => 'u-1',
            'new_values' => ['username' => 'alice'],
            'created_at' => Carbon::parse('2026-06-01 12:00:00'),
        ]);

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => AuditAction::ROLE_CREATED->value,
            'module' => 'AUTH',
            'entity_type' => 'ROLE',
            'entity_id' => 'r-1',
            'new_values' => ['code' => 'MANAGER'],
            'created_at' => Carbon::parse('2026-06-15 12:00:00'),
        ]);

        // Filter by action
        $resAction = $this->actingAs($admin, 'web')->getJson('/api/v1/audit-logs?action=USER_CREATED');
        $resAction->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals('USER_CREATED', $resAction->json('data.0.action'));

        // Filter by entity_type
        $resType = $this->actingAs($admin, 'web')->getJson('/api/v1/audit-logs?entity_type=ROLE');
        $resType->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals('ROLE', $resType->json('data.0.subject_type'));

        // Filter by date range
        $resDate = $this->actingAs($admin, 'web')->getJson('/api/v1/audit-logs?date_from=2026-06-10&date_to=2026-06-20');
        $resDate->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals('ROLE_CREATED', $resDate->json('data.0.action'));
    }

    public function test_show_single_audit_log_by_uuid(): void
    {
        $admin = $this->createAuditor();

        $log = AuditLog::create([
            'user_id' => $admin->id,
            'action' => AuditAction::USER_STATUS_CHANGED->value,
            'module' => 'AUTH',
            'entity_type' => 'USER',
            'entity_id' => 'user-uuid-xyz',
            'old_values' => ['status' => 'ACTIVE'],
            'new_values' => ['status' => 'DISABLED'],
            'ip_address' => '10.0.0.99',
            'user_agent' => 'AdminBrowser',
        ]);

        $response = $this->actingAs($admin, 'web')->getJson("/api/v1/audit-logs/{$log->uuid}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.uuid', $log->uuid)
            ->assertJsonPath('data.action', 'USER_STATUS_CHANGED')
            ->assertJsonPath('data.actor.uuid', $admin->uuid)
            ->assertJsonPath('data.subject_type', 'USER')
            ->assertJsonPath('data.subject_uuid', 'user-uuid-xyz')
            ->assertJsonPath('data.old_values.status', 'ACTIVE')
            ->assertJsonPath('data.new_values.status', 'DISABLED');
    }

    public function test_audit_log_actor_relationship_works_when_user_soft_deleted(): void
    {
        $admin = $this->createAuditor();
        $log = AuditLog::create([
            'user_id' => $admin->id,
            'action' => AuditAction::USER_CREATED->value,
            'module' => 'AUTH',
            'entity_type' => 'USER',
            'entity_id' => 'target-uuid',
            'new_values' => ['username' => 'test_user'],
        ]);

        $admin->delete(); // Soft delete actor

        $newAuditor = $this->createAuditor();

        $response = $this->actingAs($newAuditor, 'web')->getJson("/api/v1/audit-logs/{$log->uuid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.actor.uuid', $admin->uuid);
    }
}
