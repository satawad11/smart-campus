<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserSession;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
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

    public function test_unauthenticated_request_is_401(): void
    {
        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(401);
    }

    public function test_inactive_or_disabled_user_is_blocked(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!'),
            'status' => UserStatus::DISABLED,
        ]);

        $this->actingAs($user, 'web');

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(403);
    }

    public function test_revoked_session_is_blocked(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!'),
            'status' => UserStatus::ACTIVE,
        ]);

        $this->actingAs($user, 'web');

        // Mark current tracked session as revoked
        UserSession::where('user_id', $user->id)
            ->where('session_id', $this->trackedWebSessionId)
            ->update(['revoked_at' => now()]);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(401);
    }

    public function test_valid_current_password_and_valid_new_password_succeeds(): void
    {
        $user = User::factory()->create([
            'username' => 'self_user',
            'password' => Hash::make('OldPassword123!'),
            'status' => UserStatus::ACTIVE,
            'must_change_password' => true,
            'remember_token' => 'old_remember_token',
            'mfa_enabled' => true,
            'mfa_secret_encrypted' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        ]);

        $this->actingAs($user, 'web');

        $otherSession = UserSession::create([
            'user_id' => $user->id,
            'session_id' => 'other_active_session',
            'ip_address' => '192.168.1.100',
            'user_agent' => 'OtherDevice',
            'login_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword123!',
            'password' => 'BrandNewPassword456!',
            'password_confirmation' => 'BrandNewPassword456!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Password changed successfully')
            ->assertJsonPath('data.user.must_change_password', false)
            ->assertJsonPath('data.user.mfa_enabled', true);

        $user->refresh();

        // 1. Password changed and old password no longer works
        $this->assertTrue(Hash::check('BrandNewPassword456!', $user->password));
        $this->assertFalse(Hash::check('OldPassword123!', $user->password));

        // 2. must_change_password cleared
        $this->assertFalse($user->must_change_password);

        // 3. password_changed_at updated
        $this->assertNotNull($user->password_changed_at);

        // 4. remember_token cleared
        $this->assertNull($user->remember_token);

        // 5. MFA untouched
        $this->assertTrue($user->mfa_enabled);
        $this->assertNotNull($user->mfa_secret_encrypted);

        // 6. Other sessions revoked
        $otherSession->refresh();
        $this->assertNotNull($otherSession->revoked_at);

        // 7. Audit log recorded
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::USER_PASSWORD_CHANGED->value,
            'entity_type' => 'USER',
            'entity_id' => $user->uuid,
            'user_id' => $user->id,
        ]);

        $log = AuditLog::where('action', AuditAction::USER_PASSWORD_CHANGED->value)->first();
        $this->assertNotNull($log);
        $this->assertEquals(['must_change_password' => false], $log->new_values);
        $this->assertEquals(1, $log->metadata['other_sessions_revoked']);

        // 8. Plaintext passwords never in response or audit log
        $resJson = json_encode($response->json());
        $logJson = json_encode($log->toArray());
        $this->assertStringNotContainsString('OldPassword123!', $resJson);
        $this->assertStringNotContainsString('BrandNewPassword456!', $resJson);
        $this->assertStringNotContainsString('OldPassword123!', $logJson);
        $this->assertStringNotContainsString('BrandNewPassword456!', $logJson);
    }

    public function test_invalid_current_password_returns_422(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123!'),
            'status' => UserStatus::ACTIVE,
        ]);

        $this->actingAs($user, 'web');

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'WrongPassword123!',
            'password' => 'BrandNewPassword456!',
            'password_confirmation' => 'BrandNewPassword456!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['current_password']]]);
    }

    public function test_weak_or_short_password_returns_422(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123!'),
            'status' => UserStatus::ACTIVE,
        ]);

        $this->actingAs($user, 'web');

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'CorrectPassword123!',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['password']]]);
    }

    public function test_confirmation_mismatch_returns_422(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123!'),
            'status' => UserStatus::ACTIVE,
        ]);

        $this->actingAs($user, 'web');

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'CorrectPassword123!',
            'password' => 'BrandNewPassword456!',
            'password_confirmation' => 'MismatchPassword789!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['password']]]);
    }

    public function test_same_password_as_current_returns_422(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123!'),
            'status' => UserStatus::ACTIVE,
        ]);

        $this->actingAs($user, 'web');

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'CorrectPassword123!',
            'password' => 'CorrectPassword123!',
            'password_confirmation' => 'CorrectPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['password']]]);
    }

    public function test_new_password_can_authenticate_on_subsequent_login(): void
    {
        $user = User::factory()->create([
            'username' => 'login_tester',
            'password' => Hash::make('InitialPassword123!'),
            'status' => UserStatus::ACTIVE,
        ]);

        $this->actingAs($user, 'web');

        // Change password
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'InitialPassword123!',
            'password' => 'NextSecurePassword999!',
            'password_confirmation' => 'NextSecurePassword999!',
        ])->assertStatus(200);

        // Attempt login with old password -> fail
        $failLogin = $this->postJson('/api/v1/auth/login', [
            'username' => 'login_tester',
            'password' => 'InitialPassword123!',
        ]);
        $failLogin->assertStatus(401);

        // Attempt login with new password -> success
        $successLogin = $this->postJson('/api/v1/auth/login', [
            'username' => 'login_tester',
            'password' => 'NextSecurePassword999!',
        ]);
        $successLogin->assertStatus(200);
    }
}
