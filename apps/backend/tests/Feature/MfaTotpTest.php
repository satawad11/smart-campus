<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaTotpTest extends TestCase
{
    use RefreshDatabase;

    protected Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->google2fa = new Google2FA;
        $this->withoutMiddleware(ValidateCsrfToken::class);
        RateLimiter::clear('mfa');
    }

    /*
    |--------------------------------------------------------------------------
    | MFA Setup Tests (1 - 7)
    |--------------------------------------------------------------------------
    */

    public function test_unauthenticated_user_cannot_start_mfa_setup(): void
    {
        $response = $this->postJson('/api/v1/auth/mfa/setup');
        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
            'error' => [
                'code' => 'UNAUTHENTICATED',
            ],
        ]);
    }

    public function test_authenticated_user_can_start_setup(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => false,
        ]);

        $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/setup');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'secret',
                'qr_code_url',
                'issuer',
                'account_name',
                'expires_in_seconds',
            ],
            'meta',
        ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data['secret']);
        $this->assertStringContainsString('otpauth://totp/', $data['qr_code_url']);
        $this->assertSame(600, $data['expires_in_seconds']);

        // Session contains pending setup with encrypted secret
        $this->assertTrue(session()->has('auth.pending_mfa_setup'));
        $pending = session()->get('auth.pending_mfa_setup');
        $this->assertSame($user->uuid, $pending['user_uuid']);
        $this->assertNotEmpty($pending['secret_encrypted']);
    }

    public function test_setup_does_not_immediately_set_mfa_enabled(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => false,
        ]);

        $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/setup');

        $user->refresh();
        $this->assertFalse($user->mfa_enabled);
        $this->assertNull($user->mfa_secret_encrypted);
    }

    public function test_pending_setup_expires(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => false,
        ]);

        $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/setup');
        $secret = $response->json('data.secret');

        // Advance past the pending-setup expiry window (robust to session
        // internals, which tracked-session pinning would otherwise perturb).
        $this->travel(((int) config('auth_security.mfa_setup_minutes', 10)) + 1)->minutes();

        $validOtp = $this->google2fa->getCurrentOtp($secret);

        $confirmResponse = $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/confirm', [
            'code' => $validOtp,
        ]);

        $confirmResponse->assertStatus(422);
        $confirmResponse->assertJson([
            'success' => false,
            'error' => [
                'code' => 'AUTH_MFA_INVALID_CODE',
            ],
        ]);

        $user->refresh();
        $this->assertFalse($user->mfa_enabled);
    }

    public function test_pending_setup_secret_is_not_written_as_active_secret_before_confirmation(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => false,
        ]);

        $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/setup');

        $user->refresh();
        $this->assertNull($user->mfa_secret_encrypted);
    }

    public function test_setup_response_does_not_expose_encrypted_db_value(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => false,
        ]);

        $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/setup');
        $data = $response->json('data');

        $this->assertArrayNotHasKey('mfa_secret_encrypted', $data);
        $this->assertArrayNotHasKey('secret_encrypted', $data);
    }

    public function test_already_enabled_mfa_cannot_be_silently_overwritten(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
            'mfa_secret_encrypted' => Crypt::encryptString($this->google2fa->generateSecretKey()),
        ]);

        $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/setup');

        $response->assertStatus(409);
        $response->assertJson([
            'success' => false,
            'error' => [
                'code' => 'AUTH_MFA_ALREADY_ENABLED',
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | MFA Confirmation Tests (8 - 13)
    |--------------------------------------------------------------------------
    */

    public function test_valid_totp_confirms_enrollment(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => false,
        ]);

        $setupResponse = $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/setup');
        $secret = $setupResponse->json('data.secret');
        $otp = $this->google2fa->getCurrentOtp($secret);

        $confirmResponse = $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/confirm', [
            'code' => $otp,
        ]);

        $confirmResponse->assertStatus(200);
        $confirmResponse->assertJson([
            'success' => true,
            'message' => 'MFA confirmed and enabled successfully',
            'data' => [
                'mfa_enabled' => true,
            ],
        ]);

        $user->refresh();
        $this->assertTrue($user->mfa_enabled);
        $this->assertNotNull($user->mfa_secret_encrypted);

        // Secret is encrypted and recovers the setup secret
        $this->assertSame($secret, Crypt::decryptString($user->mfa_secret_encrypted));

        // Pending setup is cleared
        $this->assertFalse(session()->has('auth.pending_mfa_setup'));
    }

    public function test_invalid_totp_does_not_enable_mfa(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => false,
        ]);

        $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/setup');

        $confirmResponse = $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/confirm', [
            'code' => '000000',
        ]);

        $confirmResponse->assertStatus(422);
        $confirmResponse->assertJson([
            'success' => false,
            'error' => [
                'code' => 'AUTH_MFA_INVALID_CODE',
            ],
        ]);

        $user->refresh();
        $this->assertFalse($user->mfa_enabled);
        $this->assertNull($user->mfa_secret_encrypted);
    }

    public function test_raw_secret_does_not_appear_in_serialized_user_response(): void
    {
        $secret = $this->google2fa->generateSecretKey();
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
            'mfa_secret_encrypted' => Crypt::encryptString($secret),
        ]);

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertArrayNotHasKey('mfa_secret_encrypted', $json['data']['user']);
        $this->assertArrayNotHasKey('secret', $json['data']['user']);
        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    /*
    |--------------------------------------------------------------------------
    | Login MFA Verification Tests (14 - 25)
    |--------------------------------------------------------------------------
    */

    public function test_valid_mfa_code_completes_login_authentication(): void
    {
        $role = Role::create([
            'code' => 'TEST_ROLE',
            'name' => 'Test Role',
            'system_role' => true,
            'status' => RoleStatus::ACTIVE,
        ]);

        $secret = $this->google2fa->generateSecretKey();
        $user = User::factory()->create([
            'username' => 'mfa_user',
            'password' => 'password123',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
            'mfa_secret_encrypted' => Crypt::encryptString($secret),
            'last_login_at' => null,
        ]);
        $user->roles()->attach($role->id);

        // 1. Password login step yields MFA_REQUIRED and sets auth.pending_mfa
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'username' => 'mfa_user',
            'password' => 'password123',
        ]);

        $loginResponse->assertStatus(200);
        $loginResponse->assertJson([
            'success' => true,
            'data' => [
                'status' => 'MFA_REQUIRED',
            ],
        ]);

        $this->assertGuest('web');
        $this->assertDatabaseCount('user_sessions', 0);
        $this->assertTrue(session()->has('auth.pending_mfa'));

        $preSessionId = session()->getId();

        // 2. Submit valid OTP to verify endpoint
        $otp = $this->google2fa->getCurrentOtp($secret);

        $verifyResponse = $this->postJson('/api/v1/auth/mfa/verify', [
            'code' => $otp,
        ]);

        $verifyResponse->assertStatus(200);
        $verifyResponse->assertJson([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'uuid' => $user->uuid,
                    'username' => 'mfa_user',
                    'mfa_enabled' => true,
                ],
            ],
        ]);

        // User is authenticated
        $this->assertAuthenticatedAs($user, 'web');

        // Session ID is regenerated
        $postSessionId = session()->getId();
        $this->assertNotSame($preSessionId, $postSessionId);

        // Exactly one user_sessions row created
        $this->assertDatabaseCount('user_sessions', 1);
        $session = UserSession::where('user_id', $user->id)->first();
        $this->assertNotNull($session);
        $this->assertSame($postSessionId, $session->session_id);

        // last_login_at is updated
        $user->refresh();
        $this->assertNotNull($user->last_login_at);

        // Pending challenge is consumed
        $this->assertFalse(session()->has('auth.pending_mfa'));

        // Replay of same challenge fails
        $replayResponse = $this->postJson('/api/v1/auth/mfa/verify', [
            'code' => $otp,
        ]);
        $replayResponse->assertStatus(401);
        $replayResponse->assertJson([
            'success' => false,
            'error' => [
                'code' => 'AUTH_MFA_CHALLENGE_EXPIRED',
            ],
        ]);
    }

    public function test_invalid_mfa_code_fails_login_without_authenticating(): void
    {
        $secret = $this->google2fa->generateSecretKey();
        $user = User::factory()->create([
            'username' => 'mfa_user2',
            'password' => 'password123',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
            'mfa_secret_encrypted' => Crypt::encryptString($secret),
            'last_login_at' => null,
        ]);

        // Login password step
        $this->postJson('/api/v1/auth/login', [
            'username' => 'mfa_user2',
            'password' => 'password123',
        ]);

        // Submit invalid OTP
        $verifyResponse = $this->postJson('/api/v1/auth/mfa/verify', [
            'code' => '000000',
        ]);

        $verifyResponse->assertStatus(401);
        $verifyResponse->assertJson([
            'success' => false,
            'error' => [
                'code' => 'AUTH_MFA_INVALID_CODE',
            ],
        ]);

        $this->assertGuest('web');
        $this->assertDatabaseCount('user_sessions', 0);

        $user->refresh();
        $this->assertNull($user->last_login_at);
    }

    public function test_expired_mfa_challenge_is_rejected_and_cleared(): void
    {
        $secret = $this->google2fa->generateSecretKey();
        $user = User::factory()->create([
            'username' => 'mfa_user3',
            'password' => 'password123',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
            'mfa_secret_encrypted' => Crypt::encryptString($secret),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'mfa_user3',
            'password' => 'password123',
        ]);

        // Expire challenge
        $pending = session()->get('auth.pending_mfa');
        $pending['expires_at'] = now()->subMinutes(10)->timestamp;
        session()->put('auth.pending_mfa', $pending);

        $otp = $this->google2fa->getCurrentOtp($secret);
        $response = $this->postJson('/api/v1/auth/mfa/verify', [
            'code' => $otp,
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'error' => [
                'code' => 'AUTH_MFA_CHALLENGE_EXPIRED',
            ],
        ]);

        $this->assertFalse(session()->has('auth.pending_mfa'));
        $this->assertGuest('web');
    }

    /*
    |--------------------------------------------------------------------------
    | MFA Disable Tests (26 - 30)
    |--------------------------------------------------------------------------
    */

    public function test_unauthenticated_user_cannot_disable_mfa(): void
    {
        $response = $this->postJson('/api/v1/auth/mfa/disable', [
            'password' => 'password123',
            'code' => '123456',
        ]);

        $response->assertStatus(401);
    }

    public function test_invalid_password_or_code_cannot_disable_mfa(): void
    {
        $secret = $this->google2fa->generateSecretKey();
        $user = User::factory()->create([
            'password' => 'correct_pass_123',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
            'mfa_secret_encrypted' => Crypt::encryptString($secret),
        ]);

        $validOtp = $this->google2fa->getCurrentOtp($secret);

        // Wrong password + valid OTP
        $response1 = $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/disable', [
            'password' => 'wrong_password',
            'code' => $validOtp,
        ]);
        $response1->assertStatus(422);

        // Correct password + invalid OTP
        $response2 = $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/disable', [
            'password' => 'correct_pass_123',
            'code' => '000000',
        ]);
        $response2->assertStatus(422);

        $user->refresh();
        $this->assertTrue($user->mfa_enabled);
        $this->assertNotNull($user->mfa_secret_encrypted);
    }

    public function test_successful_disable_clears_mfa_enabled_and_secret(): void
    {
        $secret = $this->google2fa->generateSecretKey();
        $user = User::factory()->create([
            'password' => 'correct_pass_123',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
            'mfa_secret_encrypted' => Crypt::encryptString($secret),
        ]);

        $otp = $this->google2fa->getCurrentOtp($secret);

        $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/disable', [
            'password' => 'correct_pass_123',
            'code' => $otp,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'MFA disabled successfully',
            'data' => [
                'mfa_enabled' => false,
            ],
        ]);

        $user->refresh();
        $this->assertFalse($user->mfa_enabled);
        $this->assertNull($user->mfa_secret_encrypted);
    }

    /*
    |--------------------------------------------------------------------------
    | Security & CSRF Tests (31 - 38)
    |--------------------------------------------------------------------------
    */

    public function test_csrf_protection_enforced_on_mfa_endpoints(): void
    {
        // ValidateCsrfToken bypasses runningUnitTests(), so assert the wiring +
        // 419 error contract rather than a live 419 (see AuthApiTest rationale).
        // The MFA endpoints inherit CSRF protection from the api middleware group.
        $kernel = app(Kernel::class);
        $property = (new \ReflectionObject($kernel))->getProperty('middlewareGroups');
        $property->setAccessible(true);
        $apiGroup = $property->getValue($kernel)['api'] ?? [];

        $this->assertContains(ValidateCsrfToken::class, $apiGroup);

        $request = Request::create('/api/v1/auth/mfa/setup', 'POST');
        $request->headers->set('Accept', 'application/json');
        $response = app(ExceptionHandler::class)
            ->render($request, new TokenMismatchException('CSRF token mismatch'));

        $this->assertSame(419, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('CSRF_MISMATCH', $data['error']['code']);
    }
}
