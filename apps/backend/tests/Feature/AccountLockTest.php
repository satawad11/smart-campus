<?php

namespace Tests\Feature;

use App\Enums\AuthStatus;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Auth\AccountSecurityService;
use App\Services\Auth\AuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AccountLockTest extends TestCase
{
    use RefreshDatabase;

    protected AccountSecurityService $accountSecurityService;

    protected AuthenticationService $authService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountSecurityService = app(AccountSecurityService::class);
        $this->authService = app(AuthenticationService::class);
    }

    public function test_active_user_can_be_persistently_locked(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'locked_until' => null,
        ]);

        $this->assertTrue($user->status === UserStatus::ACTIVE);
        $this->assertFalse($this->accountSecurityService->isPersistentlyLocked($user));

        $locked = $this->accountSecurityService->lockUser($user);

        $this->assertSame(UserStatus::LOCKED, $locked->status);
        $this->assertTrue($this->accountSecurityService->isPersistentlyLocked($locked));

        $user->refresh();
        $this->assertSame(UserStatus::LOCKED, $user->status);
    }

    public function test_persistent_lock_does_not_use_locked_until(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'locked_until' => null,
        ]);

        $this->accountSecurityService->lockUser($user);
        $user->refresh();

        // Persistent administrative lock must NOT set or use locked_until
        $this->assertSame(UserStatus::LOCKED, $user->status);
        $this->assertNull($user->locked_until);
        $this->assertFalse($this->accountSecurityService->isTemporarilyLocked($user));
    }

    public function test_persistently_locked_user_cannot_authenticate(): void
    {
        $user = User::factory()->create([
            'username' => 'locked_admin',
            'password' => 'secret_password_123',
            'status' => UserStatus::ACTIVE,
        ]);

        // Lock user administratively
        $this->accountSecurityService->lockUser($user);

        // Attempt login with correct password
        $result = $this->authService->authenticate('locked_admin', 'secret_password_123');

        $this->assertSame(AuthStatus::ACCOUNT_LOCKED, $result->getStatus());
        $this->assertSame('AUTH_ACCOUNT_LOCKED', $result->getErrorCode());
        $this->assertFalse($result->isSuccessful());
    }

    public function test_unlocking_changes_persistent_locked_status_back_to_active(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::LOCKED,
        ]);

        $this->assertSame(UserStatus::LOCKED, $user->status);

        $unlocked = $this->accountSecurityService->unlockUser($user);

        $this->assertSame(UserStatus::ACTIVE, $unlocked->status);
        $this->assertFalse($this->accountSecurityService->isPersistentlyLocked($unlocked));

        $user->refresh();
        $this->assertSame(UserStatus::ACTIVE, $user->status);
    }

    public function test_unlock_resets_temporary_failed_login_count_and_locked_until(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::LOCKED,
            'failed_login_count' => 5,
            'locked_until' => now()->addMinutes(15),
        ]);

        $this->accountSecurityService->unlockUser($user);
        $user->refresh();

        $this->assertSame(UserStatus::ACTIVE, $user->status);
        $this->assertSame(0, $user->failed_login_count);
        $this->assertNull($user->locked_until);
        $this->assertFalse($this->accountSecurityService->isTemporarilyLocked($user));
    }

    public function test_locking_disabled_or_expired_account_is_rejected(): void
    {
        $disabledUser = User::factory()->create([
            'status' => UserStatus::DISABLED,
        ]);

        $expiredUser = User::factory()->create([
            'status' => UserStatus::EXPIRED,
        ]);

        try {
            $this->accountSecurityService->lockUser($disabledUser);
            $this->fail('Expected InvalidArgumentException for disabled user');
        } catch (InvalidArgumentException $e) {
            // Expected
        }

        try {
            $this->accountSecurityService->lockUser($expiredUser);
            $this->fail('Expected InvalidArgumentException for expired user');
        } catch (InvalidArgumentException $e) {
            // Expected
        }

        $disabledUser->refresh();
        $expiredUser->refresh();

        $this->assertSame(UserStatus::DISABLED, $disabledUser->status);
        $this->assertSame(UserStatus::EXPIRED, $expiredUser->status);
    }

    public function test_unlocking_disabled_or_expired_account_is_rejected(): void
    {
        $disabledUser = User::factory()->create([
            'status' => UserStatus::DISABLED,
        ]);

        $expiredUser = User::factory()->create([
            'status' => UserStatus::EXPIRED,
        ]);

        try {
            $this->accountSecurityService->unlockUser($disabledUser);
            $this->fail('Expected InvalidArgumentException for disabled user');
        } catch (InvalidArgumentException $e) {
            // Expected
        }

        try {
            $this->accountSecurityService->unlockUser($expiredUser);
            $this->fail('Expected InvalidArgumentException for expired user');
        } catch (InvalidArgumentException $e) {
            // Expected
        }

        $disabledUser->refresh();
        $expiredUser->refresh();

        $this->assertSame(UserStatus::DISABLED, $disabledUser->status);
        $this->assertSame(UserStatus::EXPIRED, $expiredUser->status);
    }

    public function test_temporary_lockout_and_persistent_lock_remain_independent(): void
    {
        $user = User::factory()->create([
            'username' => 'temp_locked_user',
            'password' => 'correct_pass_123',
            'status' => UserStatus::ACTIVE,
            'failed_login_count' => 5,
            'locked_until' => now()->addMinutes(15),
        ]);

        // User is temporarily locked, but status is still ACTIVE
        $this->assertSame(UserStatus::ACTIVE, $user->status);
        $this->assertTrue($this->accountSecurityService->isTemporarilyLocked($user));
        $this->assertFalse($this->accountSecurityService->isPersistentlyLocked($user));

        // Temporary lock blocks login
        $result = $this->authService->authenticate('temp_locked_user', 'correct_pass_123');
        $this->assertSame(AuthStatus::ACCOUNT_LOCKED, $result->getStatus());

        // When locked_until expires, temporary lock no longer applies
        $user->forceFill(['locked_until' => now()->subMinute()])->save();
        $this->assertFalse($this->accountSecurityService->isTemporarilyLocked($user));

        $resultAfterExpiry = $this->authService->authenticate('temp_locked_user', 'correct_pass_123');
        $this->assertTrue($resultAfterExpiry->isSuccessful());
    }
}
