<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\AuthenticationService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_active_non_mfa_user_with_valid_credentials_receives_successful_login(): void
    {
        $role = Role::create([
            'code' => 'TEST_ROLE',
            'name' => 'Test Role',
            'system_role' => true,
            'status' => RoleStatus::ACTIVE,
        ]);

        $user = User::factory()->create([
            'username' => 'johndoe',
            'password' => 'password123',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => false,
            'must_change_password' => false,
            'last_login_at' => null,
        ]);
        $user->roles()->attach($role->id);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'johndoe',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'uuid' => $user->uuid,
                    'username' => 'johndoe',
                    'email' => $user->email,
                    'status' => 'ACTIVE',
                    'mfa_enabled' => false,
                    'must_change_password' => false,
                    'roles' => [
                        [
                            'uuid' => $role->uuid,
                            'code' => 'TEST_ROLE',
                            'name' => 'Test Role',
                        ],
                    ],
                ],
            ],
            'meta' => null,
        ]);

        // Asserts authentication succeeded
        $this->assertAuthenticatedAs($user, 'web');

        // Verify last_login_at was updated
        $user->refresh();
        $this->assertNotNull($user->last_login_at);

        // Verify user_sessions tracking record
        $this->assertDatabaseCount('user_sessions', 1);
        $session = UserSession::where('user_id', $user->id)->first();
        $this->assertNotNull($session);
        $this->assertNotEmpty($session->uuid);
        $this->assertNotNull($session->login_at);
        $this->assertNotNull($session->last_activity_at);
        $this->assertNull($session->logout_at);
        $this->assertSame(session()->getId(), $session->session_id);

        // Response does not expose sensitive internals
        $data = $response->json('data.user');
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('remember_token', $data);
        $this->assertArrayNotHasKey('mfa_secret_encrypted', $data);
        $this->assertArrayNotHasKey('failed_login_count', $data);
        $this->assertArrayNotHasKey('locked_until', $data);
    }

    public function test_session_id_is_regenerated_after_login(): void
    {
        $user = User::factory()->create([
            'username' => 'fixation_test',
            'password' => 'secret123',
            'status' => UserStatus::ACTIVE,
        ]);

        $this->withSession(['pre_login' => 'val']);
        $preSessionId = session()->getId();

        $response = $this->call('POST', '/api/v1/auth/login', [
            'username' => 'fixation_test',
            'password' => 'secret123',
        ], [
            config('session.cookie') => $preSessionId,
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertStatus(200);
        $postSessionId = session()->getId();

        $this->assertNotSame($preSessionId, $postSessionId);
    }

    public function test_login_validation_errors(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Validation failed',
            'error' => [
                'code' => 'VALIDATION_ERROR',
            ],
        ]);
        $this->assertArrayHasKey('username', $response->json('error.fields'));
        $this->assertArrayHasKey('password', $response->json('error.fields'));
    }

    public function test_mfa_enabled_user_receives_mfa_challenge_response(): void
    {
        $user = User::factory()->create([
            'username' => 'mfa_admin',
            'password' => 'secret_pass',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
            'last_login_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'mfa_admin',
            'password' => 'secret_pass',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'MFA verification required',
            'data' => [
                'status' => 'MFA_REQUIRED',
                'challenge' => [
                    'type' => 'TOTP',
                    'expires_in_seconds' => 300,
                ],
            ],
            'meta' => null,
        ]);

        // Pending MFA state must be stored in session
        $this->assertTrue(session()->has('auth.pending_mfa'));
        $pendingData = session()->get('auth.pending_mfa');
        $this->assertSame($user->uuid, $pendingData['user_uuid']);
        $this->assertArrayHasKey('issued_at', $pendingData);
        $this->assertArrayHasKey('expires_at', $pendingData);

        // Must NOT be authenticated yet
        $this->assertGuest('web');

        // Must NOT create user_sessions
        $this->assertDatabaseCount('user_sessions', 0);

        // Must NOT update last_login_at
        $user->refresh();
        $this->assertNull($user->last_login_at);

        // Must not expose secret material
        $json = $response->json();
        $this->assertArrayNotHasKey('mfa_secret_encrypted', $json['data']);
        $this->assertArrayNotHasKey('id', $json['data']);
    }

    public function test_unknown_username_and_wrong_password_return_same_error_code(): void
    {
        User::factory()->create([
            'username' => 'existing_user',
            'password' => 'correct_pass',
        ]);

        // Unknown username
        $response1 = $this->postJson('/api/v1/auth/login', [
            'username' => 'unknown_user',
            'password' => 'any_pass',
        ]);
        $response1->assertStatus(401);
        $response1->assertJson([
            'success' => false,
            'message' => 'Invalid username or password',
            'error' => [
                'code' => 'AUTH_INVALID_CREDENTIALS',
            ],
        ]);

        // Wrong password
        $response2 = $this->postJson('/api/v1/auth/login', [
            'username' => 'existing_user',
            'password' => 'wrong_pass',
        ]);
        $response2->assertStatus(401);
        $response2->assertJson([
            'success' => false,
            'message' => 'Invalid username or password',
            'error' => [
                'code' => 'AUTH_INVALID_CREDENTIALS',
            ],
        ]);

        $this->assertSame($response1->json('error.code'), $response2->json('error.code'));
        $this->assertGuest('web');
        $this->assertDatabaseCount('user_sessions', 0);
    }

    public function test_temporarily_locked_user_returns_lock_response(): void
    {
        User::factory()->create([
            'username' => 'locked_guy',
            'password' => 'password123',
            'status' => UserStatus::ACTIVE,
            'locked_until' => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'locked_guy',
            'password' => 'password123',
        ]);

        $response->assertStatus(423);
        $response->assertJson([
            'success' => false,
            'message' => 'Account is temporarily locked or disabled',
            'error' => [
                'code' => 'AUTH_ACCOUNT_LOCKED',
            ],
        ]);
        $this->assertGuest('web');
    }

    public function test_persistent_locked_user_returns_lock_response(): void
    {
        User::factory()->create([
            'username' => 'hard_locked_user',
            'password' => 'password123',
            'status' => UserStatus::LOCKED,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'hard_locked_user',
            'password' => 'password123',
        ]);

        $response->assertStatus(423);
        $response->assertJson([
            'success' => false,
            'error' => [
                'code' => 'AUTH_ACCOUNT_LOCKED',
            ],
        ]);
        $this->assertGuest('web');
    }

    public function test_disabled_user_returns_disabled_response(): void
    {
        User::factory()->create([
            'username' => 'disabled_guy',
            'password' => 'password123',
            'status' => UserStatus::DISABLED,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'disabled_guy',
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Account is disabled',
            'error' => [
                'code' => 'AUTH_ACCOUNT_DISABLED',
            ],
        ]);
        $this->assertGuest('web');
    }

    public function test_expired_user_returns_expired_response(): void
    {
        User::factory()->create([
            'username' => 'expired_guy',
            'password' => 'password123',
            'status' => UserStatus::EXPIRED,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'expired_guy',
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Account has expired',
            'error' => [
                'code' => 'AUTH_ACCOUNT_EXPIRED',
            ],
        ]);
        $this->assertGuest('web');
    }

    public function test_unauthenticated_request_to_me_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
            'error' => [
                'code' => 'UNAUTHENTICATED',
            ],
        ]);
    }

    public function test_authenticated_user_can_access_me_endpoint(): void
    {
        $role = Role::create([
            'code' => 'ADMIN',
            'name' => 'Administrator',
            'system_role' => true,
            'status' => RoleStatus::ACTIVE,
        ]);

        $user = User::factory()->create([
            'username' => 'sysadmin',
            'email' => 'admin@campus.edu',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => false,
        ]);
        $user->roles()->attach($role->id);

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Success',
            'data' => [
                'user' => [
                    'uuid' => $user->uuid,
                    'username' => 'sysadmin',
                    'email' => 'admin@campus.edu',
                    'status' => 'ACTIVE',
                    'mfa_enabled' => false,
                    'roles' => [
                        [
                            'uuid' => $role->uuid,
                            'code' => 'ADMIN',
                            'name' => 'Administrator',
                        ],
                    ],
                ],
            ],
            'meta' => null,
        ]);

        $userData = $response->json('data.user');
        $this->assertArrayNotHasKey('id', $userData);
        $this->assertArrayNotHasKey('password', $userData);
        $this->assertArrayNotHasKey('remember_token', $userData);
        $this->assertArrayNotHasKey('mfa_secret_encrypted', $userData);
        $this->assertArrayNotHasKey('failed_login_count', $userData);
        $this->assertArrayNotHasKey('locked_until', $userData);
    }

    public function test_unauthenticated_logout_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
            'error' => [
                'code' => 'UNAUTHENTICATED',
            ],
        ]);
    }

    public function test_authenticated_logout_invalidates_session_and_updates_user_session(): void
    {
        $user = User::factory()->create([
            'username' => 'logout_user',
            'password' => 'password123',
            'status' => UserStatus::ACTIVE,
        ]);

        $this->actingAs($user, 'web');
        $this->withSession(['auth_check' => true]);
        $sessionId = session()->getId();

        $session = UserSession::create([
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'login_at' => now(),
            'last_activity_at' => now(),
            'logout_at' => null,
        ]);

        $this->assertNull($session->logout_at);

        $cookieName = config('session.cookie');
        $logoutResponse = $this->call('POST', '/api/v1/auth/logout', [], [
            $cookieName => $sessionId,
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $logoutResponse->assertStatus(200);
        $logoutResponse->assertJson([
            'success' => true,
            'message' => 'Logged out successfully',
            'data' => null,
            'meta' => null,
        ]);

        // User is no longer authenticated
        $this->assertGuest('web');

        // Session record has logout_at timestamp
        $session->refresh();
        $this->assertNotNull($session->logout_at);

        // Accessing /me now fails
        $meResponse = $this->getJson('/api/v1/auth/me');
        $meResponse->assertStatus(401);
    }

    public function test_logout_does_not_revoke_other_active_sessions(): void
    {
        $user = User::factory()->create([
            'username' => 'multi_session_user',
            'password' => 'password123',
            'status' => UserStatus::ACTIVE,
        ]);

        $otherSession = UserSession::create([
            'user_id' => $user->id,
            'session_id' => 'other_session_id',
            'ip_address' => '192.168.1.100',
            'user_agent' => 'Mobile Safari',
            'login_at' => now()->subHour(),
            'last_activity_at' => now()->subHour(),
            'logout_at' => null,
        ]);

        $this->actingAs($user, 'web');
        $this->withSession(['auth_check' => true]);
        $currentSessionId = session()->getId();

        $currentSession = UserSession::create([
            'user_id' => $user->id,
            'session_id' => $currentSessionId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'login_at' => now(),
            'last_activity_at' => now(),
            'logout_at' => null,
        ]);

        $cookieName = config('session.cookie');

        $this->call('POST', '/api/v1/auth/logout', [], [
            $cookieName => $currentSessionId,
        ], [], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(200);

        $currentSession->refresh();
        $otherSession->refresh();

        $this->assertNotNull($currentSession->logout_at);
        $this->assertNull($otherSession->logout_at);
    }

    public function test_normal_successful_login_clears_pending_mfa_state(): void
    {
        $user = User::factory()->create([
            'username' => 'normal_user',
            'password' => 'password123',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => false,
        ]);

        $this->withSession([
            'auth.pending_mfa' => [
                'user_uuid' => 'dummy-uuid',
                'issued_at' => now()->timestamp,
                'expires_at' => now()->addMinutes(5)->timestamp,
            ],
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'normal_user',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $this->assertFalse(session()->has('auth.pending_mfa'));
    }

    public function test_subsequent_mfa_login_overwrites_pending_mfa_state(): void
    {
        $user1 = User::factory()->create([
            'username' => 'mfa_user1',
            'password' => 'password123',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
        ]);

        $user2 = User::factory()->create([
            'username' => 'mfa_user2',
            'password' => 'password123',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'mfa_user1',
            'password' => 'password123',
        ])->assertStatus(200);

        $this->assertSame($user1->uuid, session()->get('auth.pending_mfa.user_uuid'));

        $this->postJson('/api/v1/auth/login', [
            'username' => 'mfa_user2',
            'password' => 'password123',
        ])->assertStatus(200);

        $this->assertSame($user2->uuid, session()->get('auth.pending_mfa.user_uuid'));
    }

    public function test_logout_clears_pending_mfa_state(): void
    {
        $user = User::factory()->create([
            'username' => 'active_user',
            'password' => 'password123',
            'status' => UserStatus::ACTIVE,
        ]);

        $this->actingAs($user, 'web');
        $this->withSession([
            'auth.pending_mfa' => [
                'user_uuid' => $user->uuid,
                'issued_at' => now()->timestamp,
                'expires_at' => now()->addMinutes(5)->timestamp,
            ],
        ]);

        $response = $this->postJson('/api/v1/auth/logout');
        $response->assertStatus(200);
        $this->assertFalse(session()->has('auth.pending_mfa'));
    }

    public function test_pending_mfa_service_helper_methods(): void
    {
        $authService = app(AuthenticationService::class);
        $user = User::factory()->create([
            'username' => 'service_mfa_user',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true,
        ]);

        $session = session();

        // 1. Set state
        $authService->setPendingMfaState($session, $user);
        $this->assertTrue($session->has('auth.pending_mfa'));

        // 2. Retrieve state
        $retrieved = $authService->getPendingMfaUser($session);
        $this->assertNotNull($retrieved);
        $this->assertSame($user->id, $retrieved->id);

        // 3. Expired state returns null and forgets key
        $session->put('auth.pending_mfa.expires_at', now()->subMinute()->timestamp);
        $expiredUser = $authService->getPendingMfaUser($session);
        $this->assertNull($expiredUser);
        $this->assertFalse($session->has('auth.pending_mfa'));

        // 4. Clear state
        $authService->setPendingMfaState($session, $user);
        $authService->clearPendingMfaState($session);
        $this->assertFalse($session->has('auth.pending_mfa'));
    }

    public function test_csrf_protection_rejects_missing_or_invalid_token_on_state_changing_endpoints(): void
    {
        // Laravel's ValidateCsrfToken middleware deliberately bypasses
        // validation while runningUnitTests(), so a live 419 cannot be produced
        // from a feature test. Assert the security property directly instead:
        // (1) session + CSRF middleware are wired onto the api group, so
        //     state-changing /api/v1 routes are CSRF-protected at runtime, and
        // (2) the CSRF failure renders the standard 419 CSRF_MISMATCH envelope.
        $apiGroup = $this->apiMiddlewareGroup();
        $this->assertContains(StartSession::class, $apiGroup);
        $this->assertContains(ValidateCsrfToken::class, $apiGroup);

        $request = Request::create('/api/v1/auth/login', 'POST');
        $request->headers->set('Accept', 'application/json');
        $response = app(ExceptionHandler::class)
            ->render($request, new TokenMismatchException('CSRF token mismatch'));

        $this->assertSame(419, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('CSRF_MISMATCH', $data['error']['code']);
    }

    /**
     * The resolved HTTP kernel `api` middleware group (includes appends from
     * bootstrap/app.php that the router's group copy does not reflect).
     *
     * @return array<int, string>
     */
    private function apiMiddlewareGroup(): array
    {
        $kernel = app(Kernel::class);
        $property = (new \ReflectionObject($kernel))->getProperty('middlewareGroups');
        $property->setAccessible(true);

        return $property->getValue($kernel)['api'] ?? [];
    }
}
