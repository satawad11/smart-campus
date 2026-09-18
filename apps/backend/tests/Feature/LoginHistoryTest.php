<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\LoginLog;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Auth\MfaService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class LoginHistoryTest extends TestCase
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
        $this->assignRole($user, 'SUPER_ADMIN'); // Has audit.view and user.view

        return $user;
    }

    // ==========================================
    // RECORDING TESTS
    // ==========================================

    public function test_successful_password_login_records_history(): void
    {
        $user = User::factory()->create([
            'username' => 'alice',
            'password' => Hash::make('CorrectPassword123!'),
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => false,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50', 'HTTP_USER_AGENT' => 'TestBrowser/1.0'])
            ->postJson('/api/v1/auth/login', [
                'username' => 'alice',
                'password' => 'CorrectPassword123!',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('login_logs', [
            'user_id' => $user->id,
            'username' => 'alice',
            'success' => true,
            'failure_reason' => null,
            'ip_address' => '192.168.1.50',
            'user_agent' => 'TestBrowser/1.0',
        ]);
    }

    public function test_invalid_password_records_history(): void
    {
        $user = User::factory()->create([
            'username' => 'bob',
            'password' => Hash::make('CorrectPassword123!'),
            'status' => UserStatus::ACTIVE,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.51', 'HTTP_USER_AGENT' => 'TestBrowser/1.0'])
            ->postJson('/api/v1/auth/login', [
                'username' => 'bob',
                'password' => 'WrongPassword123!',
            ]);

        $response->assertStatus(401);

        $this->assertDatabaseHas('login_logs', [
            'user_id' => $user->id,
            'username' => 'bob',
            'success' => false,
            'failure_reason' => 'INVALID_CREDENTIALS',
            'ip_address' => '192.168.1.51',
        ]);
    }

    public function test_nonexistent_username_records_history_with_null_user(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.52', 'HTTP_USER_AGENT' => 'HackerAgent/1.0'])
            ->postJson('/api/v1/auth/login', [
                'username' => 'nonexistent_user',
                'password' => 'SomePassword123!',
            ]);

        $response->assertStatus(401);

        $this->assertDatabaseHas('login_logs', [
            'user_id' => null,
            'username' => 'nonexistent_user',
            'success' => false,
            'failure_reason' => 'INVALID_CREDENTIALS',
            'ip_address' => '192.168.1.52',
            'user_agent' => 'HackerAgent/1.0',
        ]);
    }

    public function test_temporary_locked_account_records_history(): void
    {
        $user = User::factory()->create([
            'username' => 'locked_user',
            'password' => Hash::make('ValidPassword123!'),
            'status' => UserStatus::ACTIVE,
            'locked_until' => now()->addMinutes(15),
            'failed_login_count' => 5,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'locked_user',
            'password' => 'ValidPassword123!',
        ]);

        $response->assertStatus(423);

        $this->assertDatabaseHas('login_logs', [
            'user_id' => $user->id,
            'username' => 'locked_user',
            'success' => false,
            'failure_reason' => 'ACCOUNT_LOCKED',
        ]);
    }

    public function test_persistent_locked_account_records_history(): void
    {
        $user = User::factory()->create([
            'username' => 'perma_locked',
            'password' => Hash::make('ValidPassword123!'),
            'status' => UserStatus::LOCKED,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'perma_locked',
            'password' => 'ValidPassword123!',
        ]);

        $response->assertStatus(423);

        $this->assertDatabaseHas('login_logs', [
            'user_id' => $user->id,
            'username' => 'perma_locked',
            'success' => false,
            'failure_reason' => 'ACCOUNT_LOCKED',
        ]);
    }

    public function test_disabled_account_records_history(): void
    {
        $user = User::factory()->create([
            'username' => 'disabled_user',
            'password' => Hash::make('ValidPassword123!'),
            'status' => UserStatus::DISABLED,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'disabled_user',
            'password' => 'ValidPassword123!',
        ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas('login_logs', [
            'user_id' => $user->id,
            'username' => 'disabled_user',
            'success' => false,
            'failure_reason' => 'ACCOUNT_DISABLED',
        ]);
    }

    public function test_expired_account_records_history(): void
    {
        $user = User::factory()->create([
            'username' => 'expired_user',
            'password' => Hash::make('ValidPassword123!'),
            'status' => UserStatus::EXPIRED,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'expired_user',
            'password' => 'ValidPassword123!',
        ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas('login_logs', [
            'user_id' => $user->id,
            'username' => 'expired_user',
            'success' => false,
            'failure_reason' => 'ACCOUNT_EXPIRED',
        ]);
    }

    public function test_mfa_required_password_stage_records_history(): void
    {
        $user = User::factory()->create([
            'username' => 'mfa_user',
            'password' => Hash::make('ValidPassword123!'),
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
            'mfa_secret_encrypted' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'mfa_user',
            'password' => 'ValidPassword123!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'MFA_REQUIRED');

        $this->assertDatabaseHas('login_logs', [
            'user_id' => $user->id,
            'username' => 'mfa_user',
            'success' => false,
            'failure_reason' => 'MFA_REQUIRED',
        ]);
    }

    public function test_successful_and_failed_mfa_verification_records_history(): void
    {
        $mfaService = app(MfaService::class);
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = User::factory()->create([
            'username' => 'mfa_verify_user',
            'password' => Hash::make('ValidPassword123!'),
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
            'mfa_secret_encrypted' => Crypt::encryptString($secret),
        ]);

        // Stage 1: Login to get pending challenge
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'username' => 'mfa_verify_user',
            'password' => 'ValidPassword123!',
        ]);
        $loginRes->assertStatus(200);

        // Stage 2a: Failed verify with invalid code
        $failVerifyRes = $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000']);
        $failVerifyRes->assertStatus(401);

        $this->assertDatabaseHas('login_logs', [
            'user_id' => $user->id,
            'username' => 'mfa_verify_user',
            'success' => false,
            'failure_reason' => 'MFA_FAILED',
        ]);

        // Stage 2b: Successful verify with valid TOTP code
        $validCode = (new Google2FA)->getCurrentOtp($secret);
        $successVerifyRes = $this->postJson('/api/v1/auth/mfa/verify', ['code' => $validCode]);
        $successVerifyRes->assertStatus(200);

        $this->assertDatabaseHas('login_logs', [
            'user_id' => $user->id,
            'username' => 'mfa_verify_user',
            'success' => true,
            'failure_reason' => null,
        ]);
    }

    public function test_passwords_and_secrets_are_never_persisted_in_login_logs(): void
    {
        $plaintextPassword = 'SuperSecretPlaintextPassword999!';
        $this->postJson('/api/v1/auth/login', [
            'username' => 'test_leak',
            'password' => $plaintextPassword,
        ]);

        $log = LoginLog::where('username', 'test_leak')->first();
        $this->assertNotNull($log);

        $serialized = json_encode($log->toArray());
        $this->assertStringNotContainsString($plaintextPassword, $serialized);
        $this->assertStringNotContainsString('SuperSecretPlaintextPassword999!', $serialized);
    }

    // ==========================================
    // READ API TESTS
    // ==========================================

    public function test_unauthenticated_login_history_is_401(): void
    {
        $this->getJson('/api/v1/login-history')->assertStatus(401);
    }

    public function test_authenticated_without_audit_permission_is_403(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->assignRole($user, 'SECURITY_OFFICER'); // Has monitor.view, event.view, not audit.view

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/login-history');
        $response->assertStatus(403);
    }

    public function test_permitted_user_can_list_global_login_history(): void
    {
        $auditor = $this->createAuditor();

        LoginLog::create([
            'username' => 'user1',
            'success' => true,
            'failure_reason' => null,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'Browser1',
            'created_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($auditor, 'web')->getJson('/api/v1/login-history');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'username',
                        'user',
                        'status',
                        'success',
                        'failure_reason',
                        'ip_address',
                        'user_agent',
                        'created_at',
                    ],
                ],
                'meta' => [
                    'pagination' => [
                        'current_page',
                        'per_page',
                        'total',
                        'last_page',
                    ],
                ],
            ]);
    }

    public function test_login_history_pagination(): void
    {
        $auditor = $this->createAuditor();

        for ($i = 1; $i <= 25; $i++) {
            LoginLog::create([
                'username' => "batch_user_{$i}",
                'success' => true,
                'ip_address' => '10.0.0.1',
                'user_agent' => 'BatchTest',
                'created_at' => now()->subSeconds($i),
            ]);
        }

        $res1 = $this->actingAs($auditor, 'web')->getJson('/api/v1/login-history?per_page=10&page=1');
        $res1->assertStatus(200)
            ->assertJsonPath('meta.pagination.per_page', 10)
            ->assertJsonPath('meta.pagination.total', 25)
            ->assertJsonPath('meta.pagination.last_page', 3)
            ->assertJsonCount(10, 'data');

        $res3 = $this->actingAs($auditor, 'web')->getJson('/api/v1/login-history?per_page=10&page=3');
        $res3->assertStatus(200)
            ->assertJsonCount(5, 'data');
    }

    public function test_login_history_status_and_success_filters(): void
    {
        $auditor = $this->createAuditor();

        LoginLog::create(['username' => 'succ_user', 'success' => true, 'failure_reason' => null, 'ip_address' => '10.0.0.1', 'user_agent' => 'test']);
        LoginLog::create(['username' => 'fail_user', 'success' => false, 'failure_reason' => 'INVALID_CREDENTIALS', 'ip_address' => '10.0.0.1', 'user_agent' => 'test']);
        LoginLog::create(['username' => 'mfa_user', 'success' => false, 'failure_reason' => 'MFA_REQUIRED', 'ip_address' => '10.0.0.1', 'user_agent' => 'test']);

        // Filter status=SUCCESS
        $succRes = $this->actingAs($auditor, 'web')->getJson('/api/v1/login-history?status=SUCCESS');
        $succRes->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals('succ_user', $succRes->json('data.0.username'));

        // Filter status=MFA_REQUIRED
        $mfaRes = $this->actingAs($auditor, 'web')->getJson('/api/v1/login-history?status=MFA_REQUIRED');
        $mfaRes->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals('mfa_user', $mfaRes->json('data.0.username'));

        // Filter success=false
        $failRes = $this->actingAs($auditor, 'web')->getJson('/api/v1/login-history?success=0');
        $failRes->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_login_history_date_range_filters(): void
    {
        $auditor = $this->createAuditor();

        LoginLog::create(['username' => 'past_user', 'success' => true, 'ip_address' => '10.0.0.1', 'user_agent' => 'test', 'created_at' => Carbon::parse('2026-01-01 10:00:00')]);
        LoginLog::create(['username' => 'mid_user', 'success' => true, 'ip_address' => '10.0.0.1', 'user_agent' => 'test', 'created_at' => Carbon::parse('2026-06-15 10:00:00')]);
        LoginLog::create(['username' => 'future_user', 'success' => true, 'ip_address' => '10.0.0.1', 'user_agent' => 'test', 'created_at' => Carbon::parse('2026-12-01 10:00:00')]);

        $res = $this->actingAs($auditor, 'web')->getJson('/api/v1/login-history?date_from=2026-05-01&date_to=2026-07-01');
        $res->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals('mid_user', $res->json('data.0.username'));
    }

    public function test_login_history_username_and_user_uuid_filters(): void
    {
        $auditor = $this->createAuditor();
        $targetUser = User::factory()->create(['username' => 'charlie', 'status' => UserStatus::ACTIVE]);

        LoginLog::create(['user_id' => $targetUser->id, 'username' => 'charlie', 'success' => true, 'ip_address' => '10.0.0.1', 'user_agent' => 'test']);
        LoginLog::create(['user_id' => null, 'username' => 'david', 'success' => false, 'ip_address' => '10.0.0.2', 'user_agent' => 'test']);

        // Filter by username keyword
        $kwRes = $this->actingAs($auditor, 'web')->getJson('/api/v1/login-history?keyword=char');
        $kwRes->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals('charlie', $kwRes->json('data.0.username'));

        // Filter by user UUID
        $uuidRes = $this->actingAs($auditor, 'web')->getJson('/api/v1/login-history?user_uuid='.$targetUser->uuid);
        $uuidRes->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals('charlie', $uuidRes->json('data.0.username'));
        $this->assertEquals($targetUser->uuid, $uuidRes->json('data.0.user.uuid'));
    }

    public function test_login_history_sorting_whitelist(): void
    {
        $auditor = $this->createAuditor();

        LoginLog::create(['username' => 'alpha', 'success' => true, 'ip_address' => '10.0.0.1', 'user_agent' => 'test', 'created_at' => now()->subHours(2)]);
        LoginLog::create(['username' => 'beta', 'success' => true, 'ip_address' => '10.0.0.1', 'user_agent' => 'test', 'created_at' => now()->subHour()]);

        // Default sorting: created_at desc (beta then alpha)
        $defaultRes = $this->actingAs($auditor, 'web')->getJson('/api/v1/login-history');
        $defaultRes->assertStatus(200);
        $this->assertEquals('beta', $defaultRes->json('data.0.username'));
        $this->assertEquals('alpha', $defaultRes->json('data.1.username'));

        // Sort by username asc (alpha then beta)
        $ascRes = $this->actingAs($auditor, 'web')->getJson('/api/v1/login-history?sort=username&order=asc');
        $ascRes->assertStatus(200);
        $this->assertEquals('alpha', $ascRes->json('data.0.username'));
        $this->assertEquals('beta', $ascRes->json('data.1.username'));

        // Invalid sort column rejected by validation
        $invalidRes = $this->actingAs($auditor, 'web')->getJson('/api/v1/login-history?sort=malicious_col');
        $invalidRes->assertStatus(422);
    }

    public function test_user_specific_login_history_endpoint(): void
    {
        $auditor = $this->createAuditor();
        $user1 = User::factory()->create(['username' => 'user_one', 'status' => UserStatus::ACTIVE]);
        $user2 = User::factory()->create(['username' => 'user_two', 'status' => UserStatus::ACTIVE]);

        LoginLog::create(['user_id' => $user1->id, 'username' => 'user_one', 'success' => true, 'ip_address' => '10.0.0.1', 'user_agent' => 'test']);
        LoginLog::create(['user_id' => $user2->id, 'username' => 'user_two', 'success' => true, 'ip_address' => '10.0.0.2', 'user_agent' => 'test']);

        $response = $this->actingAs($auditor, 'web')->getJson("/api/v1/users/{$user1->uuid}/login-history");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'user_one')
            ->assertJsonPath('data.0.user.uuid', $user1->uuid);
    }

    public function test_login_history_survives_user_soft_delete(): void
    {
        $auditor = $this->createAuditor();
        $user = User::factory()->create(['username' => 'deleting_user', 'status' => UserStatus::ACTIVE]);

        LoginLog::create([
            'user_id' => $user->id,
            'username' => 'deleting_user',
            'success' => true,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'test',
        ]);

        $user->delete(); // Soft delete

        $response = $this->actingAs($auditor, 'web')->getJson('/api/v1/login-history?keyword=deleting_user');
        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals('deleting_user', $response->json('data.0.username'));
    }
}
