<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserSession;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function assignPermission(User $user, string $permissionCode): void
    {
        $perm = Permission::firstOrCreate(
            ['code' => $permissionCode],
            ['module' => explode('.', $permissionCode)[0], 'name' => $permissionCode]
        );

        $role = Role::create([
            'code' => 'ROLE_'.strtoupper(str_replace('.', '_', $permissionCode)).'_'.Str::random(4),
            'name' => 'Role for '.$permissionCode,
            'system_role' => false,
            'status' => RoleStatus::ACTIVE,
        ]);

        $role->permissions()->attach($perm->id);

        UserRole::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
        ]);
    }

    // 1. Unauthenticated GET /api/v1/auth/sessions -> 401
    public function test_unauthenticated_get_auth_sessions_is_401(): void
    {
        $this->getJson('/api/v1/auth/sessions')
            ->assertStatus(401)
            ->assertJson(['error' => ['code' => 'UNAUTHENTICATED']]);
    }

    // 2 & 6. Authenticated user sees only own sessions; another user's sessions never appear
    public function test_authenticated_user_sees_only_own_sessions(): void
    {
        $user1 = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $user2 = User::factory()->create(['status' => UserStatus::ACTIVE]);

        // Create an additional session for user1
        $user1Session2 = UserSession::create([
            'user_id' => $user1->id,
            'session_id' => 'u1_sess_2',
            'ip_address' => '192.168.1.10',
            'user_agent' => 'Mozilla/5.0 User1 Mobile',
            'device_name' => 'Pixel 8',
            'login_at' => now()->subHours(2),
            'last_activity_at' => now()->subHours(1),
        ]);

        // Create a session for user2
        $user2Session = UserSession::create([
            'user_id' => $user2->id,
            'session_id' => 'u2_sess_1',
            'ip_address' => '10.0.0.5',
            'user_agent' => 'Mozilla/5.0 User2 Desktop',
            'login_at' => now()->subMinutes(30),
            'last_activity_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($user1, 'web');

        $response = $this->getJson('/api/v1/auth/sessions')->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data); // current session + user1Session2
        $uuids = array_column($data, 'uuid');
        $this->assertContains($user1Session2->uuid, $uuids);
        $this->assertNotContains($user2Session->uuid, $uuids);
    }

    // 3 & 4. Session list never exposes framework session id or internal DB id
    public function test_session_list_never_exposes_framework_session_id_or_internal_id(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');

        $response = $this->getJson('/api/v1/auth/sessions')->assertOk();
        $sessionData = $response->json('data.0');

        $this->assertArrayNotHasKey('id', $sessionData);
        $this->assertArrayNotHasKey('user_id', $sessionData);
        $this->assertArrayNotHasKey('session_id', $sessionData);
        $this->assertArrayHasKey('uuid', $sessionData);
        $this->assertArrayHasKey('ip_address', $sessionData);
        $this->assertArrayHasKey('user_agent', $sessionData);
        $this->assertArrayHasKey('login_at', $sessionData);
        $this->assertArrayHasKey('last_activity_at', $sessionData);
        $this->assertArrayHasKey('is_current', $sessionData);
    }

    // 5. Current session correctly marked is_current: true, others is_current: false
    public function test_current_session_is_correctly_marked_is_current(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');

        $otherSession = UserSession::create([
            'user_id' => $user->id,
            'session_id' => 'another_session_id',
            'ip_address' => '1.2.3.4',
            'user_agent' => 'Other Browser',
            'login_at' => now()->subDay(),
            'last_activity_at' => now()->subDay(),
        ]);

        $response = $this->getJson('/api/v1/auth/sessions')->assertOk();
        $data = $response->json('data');

        foreach ($data as $session) {
            if ($session['uuid'] === $otherSession->uuid) {
                $this->assertFalse($session['is_current']);
            } else {
                $this->assertTrue($session['is_current']);
            }
        }
    }

    // 7. Historical revoked/logout rows are represented
    public function test_historical_revoked_and_logout_rows_are_represented(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');

        $revokedSession = UserSession::create([
            'user_id' => $user->id,
            'session_id' => 'revoked_sess',
            'ip_address' => '1.2.3.5',
            'user_agent' => 'Revoked Browser',
            'login_at' => now()->subDays(3),
            'last_activity_at' => now()->subDays(3),
            'revoked_at' => now()->subDays(2),
        ]);

        $loggedOutSession = UserSession::create([
            'user_id' => $user->id,
            'session_id' => 'logged_out_sess',
            'ip_address' => '1.2.3.6',
            'user_agent' => 'Logged Out Browser',
            'login_at' => now()->subDays(5),
            'last_activity_at' => now()->subDays(5),
            'logout_at' => now()->subDays(5),
        ]);

        $response = $this->getJson('/api/v1/auth/sessions')->assertOk();
        $data = $response->json('data');

        $uuids = array_column($data, 'uuid');
        $this->assertContains($revokedSession->uuid, $uuids);
        $this->assertContains($loggedOutSession->uuid, $uuids);

        foreach ($data as $s) {
            if ($s['uuid'] === $revokedSession->uuid) {
                $this->assertNotNull($s['revoked_at']);
            }
            if ($s['uuid'] === $loggedOutSession->uuid) {
                $this->assertNotNull($s['logout_at']);
            }
        }
    }

    // 8. User can revoke own other session
    public function test_user_can_revoke_own_other_session(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');

        $otherSession = UserSession::create([
            'user_id' => $user->id,
            'session_id' => 'sess_to_revoke',
            'ip_address' => '1.2.3.7',
            'user_agent' => 'Browser',
            'login_at' => now()->subHours(2),
            'last_activity_at' => now()->subHours(1),
        ]);

        $this->deleteJson('/api/v1/auth/sessions/'.$otherSession->uuid)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Session revoked successfully']);

        $this->assertNotNull($otherSession->fresh()->revoked_at);
        // Current session must remain active
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    // 9. User cannot revoke another user's session (returns 404 to prevent enumeration)
    public function test_user_cannot_revoke_another_users_session_returns_404(): void
    {
        $user1 = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $user2 = User::factory()->create(['status' => UserStatus::ACTIVE]);

        $user2Session = UserSession::create([
            'user_id' => $user2->id,
            'session_id' => 'u2_sess',
            'ip_address' => '1.2.3.8',
            'user_agent' => 'Browser',
            'login_at' => now()->subHours(2),
            'last_activity_at' => now()->subHours(1),
        ]);

        $this->actingAs($user1, 'web');

        $this->deleteJson('/api/v1/auth/sessions/'.$user2Session->uuid)
            ->assertStatus(404);

        $this->assertNull($user2Session->fresh()->revoked_at);
    }

    // 10. Unknown session UUID -> 404
    public function test_unknown_session_uuid_returns_404(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');

        $this->deleteJson('/api/v1/auth/sessions/'.Str::uuid()->toString())
            ->assertStatus(404);
    }

    // 11. Already revoked session call is idempotent
    public function test_already_revoked_session_call_is_idempotent(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');

        $alreadyRevoked = UserSession::create([
            'user_id' => $user->id,
            'session_id' => 'already_revoked_sess',
            'ip_address' => '1.2.3.9',
            'user_agent' => 'Browser',
            'login_at' => now()->subHours(5),
            'last_activity_at' => now()->subHours(4),
            'revoked_at' => now()->subHours(3),
        ]);

        $this->deleteJson('/api/v1/auth/sessions/'.$alreadyRevoked->uuid)
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // 12. Revoked session fails next protected request
    public function test_revoked_session_fails_next_protected_request(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');

        $sess = UserSession::where('session_id', $this->trackedWebSessionId)->firstOrFail();
        $sess->update(['revoked_at' => now()]);

        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJson(['error' => ['code' => 'UNAUTHENTICATED']]);
    }

    // 13 & 14. Current-session revoke immediately logs user out and invalidates session
    public function test_current_session_revoke_immediately_logs_user_out(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');

        $currentSession = UserSession::where('session_id', $this->trackedWebSessionId)->firstOrFail();

        $this->deleteJson('/api/v1/auth/sessions/'.$currentSession->uuid)
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNotNull($currentSession->fresh()->revoked_at);

        // Next request must be 401
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJson(['error' => ['code' => 'UNAUTHENTICATED']]);
    }

    // 15, 16, 17, 18, 19. Revoke others
    public function test_revoke_others_keeps_current_session_active_and_revokes_others(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');

        $otherActive1 = UserSession::create([
            'user_id' => $user->id,
            'session_id' => 'other_active_1',
            'ip_address' => '1.1.1.1',
            'user_agent' => 'Desktop',
            'login_at' => now()->subHours(3),
            'last_activity_at' => now()->subHours(1),
        ]);

        $otherActive2 = UserSession::create([
            'user_id' => $user->id,
            'session_id' => 'other_active_2',
            'ip_address' => '1.1.1.2',
            'user_agent' => 'Mobile',
            'login_at' => now()->subHours(2),
            'last_activity_at' => now()->subHours(1),
        ]);

        $alreadyRevoked = UserSession::create([
            'user_id' => $user->id,
            'session_id' => 'already_revoked',
            'ip_address' => '1.1.1.3',
            'user_agent' => 'Old',
            'login_at' => now()->subDays(2),
            'last_activity_at' => now()->subDays(2),
            'revoked_at' => now()->subDay(),
        ]);

        $response = $this->postJson('/api/v1/auth/sessions/revoke-others')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'revoked_count' => 2,
                ],
            ]);

        $this->assertNotNull($otherActive1->fresh()->revoked_at);
        $this->assertNotNull($otherActive2->fresh()->revoked_at);
        $this->assertNotNull($alreadyRevoked->fresh()->revoked_at);

        // Current session remains active
        $this->getJson('/api/v1/auth/me')->assertOk();

        // Idempotent: running again returns 0
        $this->postJson('/api/v1/auth/sessions/revoke-others')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'revoked_count' => 0,
                ],
            ]);
    }

    // 20 & 21. Admin list requires user.view permission; unauthorized returns 403
    public function test_admin_list_requires_user_view_permission(): void
    {
        $admin = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $targetUser = User::factory()->create(['status' => UserStatus::ACTIVE]);

        $this->actingAs($admin, 'web');

        // Without user.view permission -> 403
        $this->getJson('/api/v1/users/'.$targetUser->uuid.'/sessions')
            ->assertStatus(403)
            ->assertJson(['error' => ['code' => 'PERMISSION_DENIED']]);

        // Grant user.view
        $this->assignPermission($admin, 'user.view');

        $this->getJson('/api/v1/users/'.$targetUser->uuid.'/sessions')->assertOk();
    }

    // 22. Admin sees target user's safe sessions
    public function test_admin_sees_target_users_safe_sessions(): void
    {
        $admin = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->assignPermission($admin, 'user.view');

        $targetUser = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $targetSession = UserSession::create([
            'user_id' => $targetUser->id,
            'session_id' => 'target_session_secret_id',
            'ip_address' => '10.20.30.40',
            'user_agent' => 'Target Device',
            'device_name' => 'MacBook Pro',
            'login_at' => now()->subHours(1),
            'last_activity_at' => now()->subMinutes(15),
        ]);

        $this->actingAs($admin, 'web');

        $response = $this->getJson('/api/v1/users/'.$targetUser->uuid.'/sessions')->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($targetSession->uuid, $data[0]['uuid']);
        $this->assertArrayNotHasKey('session_id', $data[0]);
        $this->assertArrayNotHasKey('id', $data[0]);
        $this->assertFalse($data[0]['is_current']);
    }

    // 23, 24, 25. Admin revoke requires user.update permission and revokes target session
    public function test_admin_can_revoke_target_session(): void
    {
        $admin = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $targetUser = User::factory()->create(['status' => UserStatus::ACTIVE]);

        $targetSession = UserSession::create([
            'user_id' => $targetUser->id,
            'session_id' => 'target_session_to_kill',
            'ip_address' => '10.20.30.41',
            'user_agent' => 'Target Device',
            'login_at' => now()->subHours(1),
            'last_activity_at' => now()->subMinutes(15),
        ]);

        $this->actingAs($admin, 'web');

        // Without user.update -> 403
        $this->deleteJson('/api/v1/users/'.$targetUser->uuid.'/sessions/'.$targetSession->uuid)
            ->assertStatus(403);

        $this->assignPermission($admin, 'user.update');

        $this->deleteJson('/api/v1/users/'.$targetUser->uuid.'/sessions/'.$targetSession->uuid)
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNotNull($targetSession->fresh()->revoked_at);
    }

    // Mismatched user and session in admin route returns 404
    public function test_admin_revoke_session_mismatch_returns_404(): void
    {
        $admin = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->assignPermission($admin, 'user.update');

        $user1 = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $user2 = User::factory()->create(['status' => UserStatus::ACTIVE]);

        $user2Session = UserSession::create([
            'user_id' => $user2->id,
            'session_id' => 'u2_sess',
            'ip_address' => '10.20.30.42',
            'user_agent' => 'Target Device',
            'login_at' => now()->subHours(1),
            'last_activity_at' => now()->subMinutes(15),
        ]);

        $this->actingAs($admin, 'web');

        // Attempt to delete user2's session through user1's endpoint -> 404
        $this->deleteJson('/api/v1/users/'.$user1->uuid.'/sessions/'.$user2Session->uuid)
            ->assertStatus(404);
    }

    // 26, 27, 28. Admin can revoke all target sessions and user status remains unchanged
    public function test_admin_can_revoke_all_target_sessions(): void
    {
        $admin = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->assignPermission($admin, 'user.update');

        $targetUser = User::factory()->create(['status' => UserStatus::ACTIVE]);

        $s1 = UserSession::create([
            'user_id' => $targetUser->id,
            'session_id' => 'target_s1',
            'ip_address' => '10.1.1.1',
            'user_agent' => 'D1',
            'login_at' => now()->subHours(3),
            'last_activity_at' => now()->subHours(1),
        ]);

        $s2 = UserSession::create([
            'user_id' => $targetUser->id,
            'session_id' => 'target_s2',
            'ip_address' => '10.1.1.2',
            'user_agent' => 'D2',
            'login_at' => now()->subHours(2),
            'last_activity_at' => now()->subHours(1),
        ]);

        $this->actingAs($admin, 'web');

        $this->postJson('/api/v1/users/'.$targetUser->uuid.'/sessions/revoke-all')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'revoked_count' => 2,
                ],
            ]);

        $this->assertNotNull($s1->fresh()->revoked_at);
        $this->assertNotNull($s2->fresh()->revoked_at);
        // Target account status remains ACTIVE (not disabled or locked)
        $this->assertSame(UserStatus::ACTIVE, $targetUser->fresh()->status);
    }

    // 29. Self-target admin revoke-all logs current admin out if current session included
    public function test_self_target_admin_revoke_all_logs_current_admin_out(): void
    {
        $admin = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->assignPermission($admin, 'user.update');

        $this->actingAs($admin, 'web');

        $currentSession = UserSession::where('session_id', $this->trackedWebSessionId)->firstOrFail();

        $this->postJson('/api/v1/users/'.$admin->uuid.'/sessions/revoke-all')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'revoked_count' => 1,
                ],
            ]);

        $this->assertNotNull($currentSession->fresh()->revoked_at);

        // Next request must be 401
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJson(['error' => ['code' => 'UNAUTHENTICATED']]);
    }

    // Bounded last_activity_at update
    public function test_bounded_last_activity_update_in_ensure_session_active(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->actingAs($user, 'web');

        $session = UserSession::where('session_id', $this->trackedWebSessionId)->firstOrFail();
        $oldActivity = now()->subMinutes(10);
        $session->forceFill(['last_activity_at' => $oldActivity])->save();

        $this->getJson('/api/v1/auth/me')->assertOk();

        $this->assertTrue($session->fresh()->last_activity_at->gt($oldActivity));
    }
}
