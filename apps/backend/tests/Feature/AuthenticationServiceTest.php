<?php

namespace Tests\Feature;

use App\Enums\AuthStatus;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\AuthenticationResult;
use App\Services\Auth\AuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AuthenticationService $authService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authService = new AuthenticationService;
    }

    public function test_valid_credentials_for_active_non_mfa_user_returns_success(): void
    {
        $user = User::factory()->create([
            'username' => 'alice',
            'password' => 'secret123',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => false,
        ]);

        $result = $this->authService->authenticate('alice', 'secret123');

        $this->assertInstanceOf(AuthenticationResult::class, $result);
        $this->assertSame(AuthStatus::SUCCESS, $result->status);
        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->requiresMfa());
        $this->assertFalse($result->failed());
        $this->assertSame('', $result->errorCode);
        $this->assertNull($result->internalReason);
        $this->assertTrue($result->user->is($user));
    }

    public function test_valid_credentials_for_active_mfa_user_returns_mfa_required(): void
    {
        $user = User::factory()->create([
            'username' => 'bob',
            'password' => 'secret123',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
        ]);

        $result = $this->authService->authenticate('bob', 'secret123');

        $this->assertSame(AuthStatus::MFA_REQUIRED, $result->status);
        $this->assertFalse($result->isSuccessful());
        $this->assertTrue($result->requiresMfa());
        $this->assertFalse($result->failed());
        $this->assertSame('MFA_REQUIRED', $result->internalReason);
        $this->assertTrue($result->user->is($user));
    }

    public function test_unknown_username_returns_invalid_credentials(): void
    {
        $result = $this->authService->authenticate('nonexistent', 'password');

        $this->assertSame(AuthStatus::INVALID_CREDENTIALS, $result->status);
        $this->assertTrue($result->failed());
        $this->assertSame('AUTH_INVALID_CREDENTIALS', $result->errorCode);
        $this->assertSame('USER_NOT_FOUND', $result->internalReason);
        $this->assertNull($result->user);
    }

    public function test_wrong_password_returns_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'username' => 'charlie',
            'password' => 'correct_pass',
            'status' => UserStatus::ACTIVE,
        ]);

        $result = $this->authService->authenticate('charlie', 'wrong_pass');

        $this->assertSame(AuthStatus::INVALID_CREDENTIALS, $result->status);
        $this->assertTrue($result->failed());
        $this->assertSame('AUTH_INVALID_CREDENTIALS', $result->errorCode);
        $this->assertSame('WRONG_PASSWORD', $result->internalReason);
        $this->assertTrue($result->user->is($user));
    }

    public function test_unknown_username_and_wrong_password_map_to_same_external_error_code(): void
    {
        User::factory()->create([
            'username' => 'existing_user',
            'password' => 'real_password',
        ]);

        $resultUnknown = $this->authService->authenticate('no_such_user', 'any_password');
        $resultWrongPass = $this->authService->authenticate('existing_user', 'bad_password');

        $this->assertSame($resultUnknown->errorCode, $resultWrongPass->errorCode);
        $this->assertSame('AUTH_INVALID_CREDENTIALS', $resultUnknown->errorCode);
    }

    public function test_disabled_user_cannot_authenticate(): void
    {
        $user = User::factory()->create([
            'username' => 'disabled_user',
            'password' => 'password123',
            'status' => UserStatus::DISABLED,
        ]);

        $result = $this->authService->authenticate('disabled_user', 'password123');

        $this->assertSame(AuthStatus::ACCOUNT_DISABLED, $result->status);
        $this->assertTrue($result->failed());
        $this->assertSame('AUTH_ACCOUNT_DISABLED', $result->errorCode);
        $this->assertSame('STATUS_DISABLED', $result->internalReason);
        $this->assertTrue($result->user->is($user));
    }

    public function test_expired_user_cannot_authenticate(): void
    {
        $user = User::factory()->create([
            'username' => 'expired_user',
            'password' => 'password123',
            'status' => UserStatus::EXPIRED,
        ]);

        $result = $this->authService->authenticate('expired_user', 'password123');

        $this->assertSame(AuthStatus::ACCOUNT_EXPIRED, $result->status);
        $this->assertTrue($result->failed());
        $this->assertSame('AUTH_ACCOUNT_EXPIRED', $result->errorCode);
        $this->assertSame('STATUS_EXPIRED', $result->internalReason);
        $this->assertTrue($result->user->is($user));
    }

    public function test_persistent_locked_user_cannot_authenticate(): void
    {
        $user = User::factory()->create([
            'username' => 'locked_user',
            'password' => 'password123',
            'status' => UserStatus::LOCKED,
        ]);

        $result = $this->authService->authenticate('locked_user', 'password123');

        $this->assertSame(AuthStatus::ACCOUNT_LOCKED, $result->status);
        $this->assertTrue($result->failed());
        $this->assertSame('AUTH_ACCOUNT_LOCKED', $result->errorCode);
        $this->assertSame('STATUS_LOCKED', $result->internalReason);
        $this->assertTrue($result->user->is($user));
    }

    public function test_temporary_locked_until_in_future_blocks_authentication(): void
    {
        $user = User::factory()->create([
            'username' => 'temp_locked_user',
            'password' => 'password123',
            'status' => UserStatus::ACTIVE,
            'locked_until' => now()->addMinutes(10),
            'failed_login_count' => 5,
        ]);

        $result = $this->authService->authenticate('temp_locked_user', 'password123');

        $this->assertSame(AuthStatus::ACCOUNT_LOCKED, $result->status);
        $this->assertTrue($result->failed());
        $this->assertSame('AUTH_ACCOUNT_LOCKED', $result->errorCode);
        $this->assertSame('TEMPORARY_LOCKED', $result->internalReason);
    }

    public function test_expired_locked_until_no_longer_blocks_login(): void
    {
        Carbon::setTestNow(now());

        $user = User::factory()->create([
            'username' => 'previously_locked_user',
            'password' => 'password123',
            'status' => UserStatus::ACTIVE,
            'locked_until' => now()->subMinutes(5),
            'failed_login_count' => 5,
        ]);

        $result = $this->authService->authenticate('previously_locked_user', 'password123');

        $this->assertSame(AuthStatus::SUCCESS, $result->status);
        $this->assertTrue($result->isSuccessful());

        $user->refresh();
        $this->assertSame(0, $user->failed_login_count);
        $this->assertNull($user->locked_until);

        Carbon::setTestNow();
    }

    public function test_wrong_password_increments_failed_login_count(): void
    {
        $user = User::factory()->create([
            'username' => 'target_user',
            'password' => 'correct_pass',
            'failed_login_count' => 0,
        ]);

        $this->authService->authenticate('target_user', 'wrong1');
        $this->assertSame(1, $user->fresh()->failed_login_count);

        $this->authService->authenticate('target_user', 'wrong2');
        $this->assertSame(2, $user->fresh()->failed_login_count);
    }

    public function test_reaching_failed_attempt_threshold_sets_locked_until(): void
    {
        Config::set('auth_security.max_failed_attempts', 3);
        Config::set('auth_security.lock_minutes', 20);

        Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00'));

        $user = User::factory()->create([
            'username' => 'lock_target',
            'password' => 'correct_pass',
            'failed_login_count' => 2,
            'locked_until' => null,
        ]);

        $this->authService->authenticate('lock_target', 'wrong');

        $user->refresh();
        $this->assertSame(3, $user->failed_login_count);
        $this->assertNotNull($user->locked_until);
        $this->assertEquals(Carbon::parse('2026-09-18 12:20:00'), $user->locked_until);

        Carbon::setTestNow();
    }

    public function test_failed_attempt_below_threshold_does_not_set_locked_until(): void
    {
        Config::set('auth_security.max_failed_attempts', 5);

        $user = User::factory()->create([
            'username' => 'safe_user',
            'password' => 'correct_pass',
            'failed_login_count' => 0,
            'locked_until' => null,
        ]);

        $this->authService->authenticate('safe_user', 'wrong');

        $user->refresh();
        $this->assertSame(1, $user->failed_login_count);
        $this->assertNull($user->locked_until);
    }

    public function test_successful_password_verification_resets_failed_count_and_clears_lock(): void
    {
        $user = User::factory()->create([
            'username' => 'recovering_user',
            'password' => 'correct_pass',
            'failed_login_count' => 4,
            'locked_until' => null,
        ]);

        $result = $this->authService->authenticate('recovering_user', 'correct_pass');

        $this->assertTrue($result->isSuccessful());
        $user->refresh();
        $this->assertSame(0, $user->failed_login_count);
        $this->assertNull($user->locked_until);
    }

    public function test_password_verification_uses_laravel_hash_behavior(): void
    {
        $plain = 'my-secure-password';
        $hashed = Hash::make($plain);

        $user = User::factory()->create([
            'username' => 'hash_test_user',
            'password' => $hashed,
        ]);

        $this->assertTrue($this->authService->authenticate('hash_test_user', $plain)->isSuccessful());
        $this->assertTrue($this->authService->authenticate('hash_test_user', 'wrong')->failed());
    }

    public function test_mfa_enabled_user_does_not_receive_authenticated_session_in_auth_004(): void
    {
        $user = User::factory()->create([
            'username' => 'mfa_user',
            'password' => 'secret',
            'mfa_enabled' => true,
        ]);

        $result = $this->authService->authenticate('mfa_user', 'secret');

        $this->assertSame(AuthStatus::MFA_REQUIRED, $result->status);
        $this->assertSame(0, UserSession::count());
    }

    public function test_service_does_not_create_user_sessions_records(): void
    {
        $user = User::factory()->create([
            'username' => 'regular_user',
            'password' => 'secret',
            'mfa_enabled' => false,
        ]);

        $result = $this->authService->authenticate('regular_user', 'secret');

        $this->assertSame(AuthStatus::SUCCESS, $result->status);
        $this->assertSame(0, UserSession::count());
    }

    public function test_account_state_failure_does_not_modify_password_or_security_fields(): void
    {
        $originalHash = Hash::make('password123');

        $user = User::factory()->create([
            'username' => 'disabled_target',
            'password' => $originalHash,
            'status' => UserStatus::DISABLED,
            'failed_login_count' => 0,
            'locked_until' => null,
        ]);

        $this->authService->authenticate('disabled_target', 'wrong');

        $user->refresh();
        $this->assertSame($originalHash, $user->password);
        $this->assertSame(0, $user->failed_login_count);
        $this->assertNull($user->locked_until);
        $this->assertSame(UserStatus::DISABLED, $user->status);
    }

    public function test_configuration_values_control_threshold_and_lock_duration(): void
    {
        Config::set('auth_security.max_failed_attempts', 2);
        Config::set('auth_security.lock_minutes', 30);

        Carbon::setTestNow(Carbon::parse('2026-09-18 10:00:00'));

        $user = User::factory()->create([
            'username' => 'config_target',
            'password' => 'password',
            'failed_login_count' => 1,
        ]);

        $this->authService->authenticate('config_target', 'wrong');

        $user->refresh();
        $this->assertSame(2, $user->failed_login_count);
        $this->assertEquals(Carbon::parse('2026-09-18 10:30:00'), $user->locked_until);

        Carbon::setTestNow();
    }

    public function test_consecutive_failure_increments_are_transactional(): void
    {
        $user = User::factory()->create([
            'username' => 'race_target',
            'password' => 'correct',
            'failed_login_count' => 0,
        ]);

        for ($i = 1; $i <= 4; $i++) {
            $this->authService->authenticate('race_target', 'bad');
            $this->assertSame($i, $user->fresh()->failed_login_count);
        }
    }
}
