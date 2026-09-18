<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserSession;
use App\Services\Auth\UserManagementService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * AUTH-010 final invariant: EnsureSessionActive fails closed for every
 * authenticated first-party session, matched by the current server-side session
 * id, with no marker/env/testing/actingAs bypass.
 *
 * The base TestCase adapts the harness (authenticateWithTrackedSession /
 * actingAs) so an authenticated web session has a matching tracked row and a
 * pinned session id — production has no such bypass.
 */
class SessionRevocationEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'BrandNewP@ssw0rd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // 1 & 5. Authenticated + active tracked session → allowed.
    public function test_active_tracked_session_is_allowed(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');

        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    // 2. Authenticated + revoked tracked session → 401.
    public function test_revoked_tracked_session_is_denied(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');

        UserSession::where('session_id', $this->trackedWebSessionId)->update(['revoked_at' => now()]);

        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJson(['error' => ['code' => 'UNAUTHENTICATED']]);
    }

    // 3 & 4. Authenticated + missing tracking row (no marker) → 401 (fail closed).
    public function test_missing_tracking_row_is_denied(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');

        UserSession::where('user_id', $user->id)->delete();

        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJson(['error' => ['code' => 'UNAUTHENTICATED']]);
    }

    public function test_revoked_session_denied_on_permission_route(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $perm = Permission::firstOrCreate(['code' => 'user.view'], ['module' => 'user', 'name' => 'user.view']);
        $role = Role::create(['code' => 'VIEWER', 'name' => 'Viewer', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach($perm->id);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $this->actingAs($user, 'web');
        $this->getJson('/api/v1/users')->assertOk();

        UserSession::where('user_id', $user->id)->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/users')->assertStatus(401)->assertJson(['error' => ['code' => 'UNAUTHENTICATED']]);
    }

    // 7. Normal password login creates a valid tracking row.
    public function test_password_login_creates_valid_tracking_row(): void
    {
        $user = User::factory()->create(['username' => 'loginrow', 'password' => 'password', 'status' => UserStatus::ACTIVE]);
        $this->postJson('/api/v1/auth/login', ['username' => 'loginrow', 'password' => 'password'])->assertOk();

        $row = UserSession::where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertNotEmpty($row->session_id);
        $this->assertNull($row->revoked_at);
    }

    // 8. MFA login creates a valid tracking row.
    public function test_mfa_login_creates_valid_tracking_row(): void
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();
        $user = User::factory()->create([
            'username' => 'mfarow', 'password' => 'password', 'status' => UserStatus::ACTIVE,
            'mfa_enabled' => true, 'mfa_secret_encrypted' => Crypt::encryptString($secret),
        ]);

        $this->postJson('/api/v1/auth/login', ['username' => 'mfarow', 'password' => 'password'])->assertOk();
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $google2fa->getCurrentOtp($secret)])->assertOk();

        $row = UserSession::where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->revoked_at);
    }

    // 9. Administrative password reset invalidates the previous tracked session.
    public function test_password_reset_invalidates_previous_session(): void
    {
        $user = User::factory()->create(['username' => 'resetme', 'password' => 'password', 'status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');
        $this->getJson('/api/v1/auth/me')->assertOk();

        app(UserManagementService::class)->resetPassword($user->fresh(), self::NEW_PASSWORD);

        $user->refresh();
        $this->assertSame(UserStatus::ACTIVE, $user->status);
        $this->getJson('/api/v1/auth/me')->assertStatus(401)->assertJson(['error' => ['code' => 'UNAUTHENTICATED']]);
    }

    // 10. Logout remains correct (sets logout_at, invalidates auth).
    public function test_logout_still_works(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');
        $row = UserSession::where('user_id', $user->id)->firstOrFail();

        $this->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertNotNull($row->fresh()->logout_at);
        $this->assertGuest('web');
    }

    // 6. Architecture: no marker/env/testing/actingAs bypass in the middleware.
    public function test_no_bypass_logic_in_middleware(): void
    {
        $src = file_get_contents(base_path('app/Http/Middleware/EnsureSessionActive.php'));

        // No bypass mechanisms: custom marker, test/env detection, or headers.
        foreach (['session_uuid', 'runningUnitTests', '->environment(', 'getenv(', 'APP_ENV', 'headers->get'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "middleware must not reference {$forbidden}");
        }

        // Must enforce by session id, fail closed on no active record.
        $this->assertStringContainsString('session_id', $src);
        $this->assertStringContainsString("whereNull('revoked_at')", $src);
        $this->assertStringContainsString('401', $src);
    }
}
