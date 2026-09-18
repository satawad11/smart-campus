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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_PASSWORD = 'Sup3rSecretPass!'; // >= 12 chars

    private const NEW_PASSWORD = 'BrandNewP@ssw0rd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        foreach (['user.view', 'user.create', 'user.update', 'user.disable'] as $code) {
            Permission::firstOrCreate(['code' => $code], ['module' => 'user', 'name' => $code]);
        }
    }

    private function perm(string $code): Permission
    {
        return Permission::firstOrCreate(['code' => $code], ['module' => explode('.', $code)[0], 'name' => $code]);
    }

    /** @param  list<string>  $codes */
    private function admin(array $codes = ['user.view', 'user.create', 'user.update', 'user.disable'], ?int $siteId = null): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create([
            'code' => 'ADMIN_'.uniqid(),
            'name' => 'Admin',
            'system_role' => false,
            'status' => RoleStatus::ACTIVE,
        ]);
        foreach ($codes as $c) {
            $role->permissions()->attach($this->perm($c)->id);
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'site_id' => $siteId]);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::firstOrCreate(
            ['code' => 'SUPER_ADMIN'],
            ['name' => 'Super Admin', 'system_role' => true, 'status' => RoleStatus::ACTIVE]
        );
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]); // global

        return $user;
    }

    // ---- LIST (1-11) ----

    public function test_list_requires_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/users')->assertStatus(401); // 1

        $noPerm = $this->admin([]); // no user.view
        $this->actingAs($noPerm, 'web')->getJson('/api/v1/users')->assertStatus(403); // 2
    }

    public function test_list_pagination_search_filter_sort(): void
    {
        $admin = $this->admin();
        User::factory()->count(25)->create(['status' => UserStatus::ACTIVE]);
        User::factory()->create(['username' => 'needle_user', 'email' => 'needle@example.com', 'status' => UserStatus::DISABLED]);

        // 3 + 4: can list, default per_page 20.
        $res = $this->actingAs($admin, 'web')->getJson('/api/v1/users');
        $res->assertOk();
        $this->assertSame(20, $res->json('meta.pagination.per_page'));
        $this->assertLessThanOrEqual(20, count($res->json('data')));

        // 5: per_page max enforced (>100 rejected).
        $this->actingAs($admin, 'web')->getJson('/api/v1/users?per_page=500')->assertStatus(422);

        // 6-7: keyword search on username/email.
        $byName = $this->actingAs($admin, 'web')->getJson('/api/v1/users?keyword=needle_user');
        $this->assertSame(1, $byName->json('meta.pagination.total'));
        $byEmail = $this->actingAs($admin, 'web')->getJson('/api/v1/users?keyword=needle@example');
        $this->assertSame(1, $byEmail->json('meta.pagination.total'));

        // 8: status filter.
        $disabled = $this->actingAs($admin, 'web')->getJson('/api/v1/users?status=DISABLED');
        $this->assertGreaterThanOrEqual(1, $disabled->json('meta.pagination.total'));
        foreach ($disabled->json('data') as $row) {
            $this->assertSame('DISABLED', $row['status']);
        }

        // 10: sort whitelist rejects unsupported field.
        $this->actingAs($admin, 'web')->getJson('/api/v1/users?sort=password')->assertStatus(422);
        $this->actingAs($admin, 'web')->getJson('/api/v1/users?sort=created_at&order=asc')->assertOk();
    }

    public function test_list_filters_by_role(): void // 9
    {
        $admin = $this->admin();
        $target = $this->superAdmin();

        $role = Role::where('code', 'SUPER_ADMIN')->first();

        // By role code.
        $byCode = $this->actingAs($admin, 'web')->getJson('/api/v1/users?role=SUPER_ADMIN');
        $byCode->assertOk();
        $this->assertContains($target->uuid, array_column($byCode->json('data'), 'uuid'));

        // By role uuid (must not break PostgreSQL uuid typing).
        $byUuid = $this->actingAs($admin, 'web')->getJson("/api/v1/users?role={$role->uuid}");
        $byUuid->assertOk();
        $this->assertContains($target->uuid, array_column($byUuid->json('data'), 'uuid'));
    }

    public function test_list_never_exposes_sensitive_fields(): void // 11
    {
        $admin = $this->admin();
        $res = $this->actingAs($admin, 'web')->getJson('/api/v1/users');
        foreach ($res->json('data') as $row) {
            foreach (['id', 'password', 'remember_token', 'mfa_secret_encrypted', 'failed_login_count', 'locked_until'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $row);
            }
        }
    }

    // ---- CREATE (12-22) ----

    public function test_create_requires_permission(): void // 12
    {
        $admin = $this->admin(['user.view']);
        $this->actingAs($admin, 'web')->postJson('/api/v1/users', [
            'username' => 'newone', 'password' => self::VALID_PASSWORD,
        ])->assertStatus(403);
    }

    public function test_create_valid_user(): void
    {
        $admin = $this->admin();
        $res = $this->actingAs($admin, 'web')->postJson('/api/v1/users', [
            'username' => 'created_user',
            'email' => 'created@example.com',
            'password' => self::VALID_PASSWORD,
            'must_change_password' => true,
        ]);

        $res->assertStatus(201); // 13
        $this->assertNotEmpty($res->json('data.uuid')); // 14 server UUID
        $this->assertArrayNotHasKey('id', $res->json('data'));

        $user = User::where('username', 'created_user')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->must_change_password); // 20
        $this->assertNull($user->person_id); // 22 no Person
        $this->assertNotSame(self::VALID_PASSWORD, $user->password); // 19 hashed
        $this->assertTrue(Hash::check(self::VALID_PASSWORD, $user->password));
        $this->assertNotNull($user->password_changed_at);
    }

    public function test_create_validation_rules(): void
    {
        $admin = $this->admin();
        User::factory()->create(['username' => 'taken', 'email' => 'taken@example.com']);

        // 15 username uniqueness
        $this->actingAs($admin, 'web')->postJson('/api/v1/users', [
            'username' => 'taken', 'password' => self::VALID_PASSWORD,
        ])->assertStatus(422);

        // 16 nullable email supported
        $this->actingAs($admin, 'web')->postJson('/api/v1/users', [
            'username' => 'noemail', 'password' => self::VALID_PASSWORD,
        ])->assertStatus(201);

        // 17 duplicate populated email rejected
        $this->actingAs($admin, 'web')->postJson('/api/v1/users', [
            'username' => 'other', 'email' => 'taken@example.com', 'password' => self::VALID_PASSWORD,
        ])->assertStatus(422);

        // 18 password policy enforced (too short)
        $this->actingAs($admin, 'web')->postJson('/api/v1/users', [
            'username' => 'shortpw', 'password' => 'short',
        ])->assertStatus(422);
    }

    public function test_create_rejects_injected_security_fields(): void // 21
    {
        $admin = $this->admin();
        foreach (['uuid' => 'x', 'id' => 5, 'failed_login_count' => 9, 'mfa_secret_encrypted' => 'x', 'locked_until' => now()->toString(), 'last_login_at' => now()->toString()] as $field => $value) {
            $this->actingAs($admin, 'web')->postJson('/api/v1/users', [
                'username' => 'inject_'.$field,
                'password' => self::VALID_PASSWORD,
                $field => $value,
            ])->assertStatus(422);
        }
    }

    // ---- SHOW / UPDATE (23-30) ----

    public function test_show_requires_permission_and_handles_unknown(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        // 23 show requires user.view
        $noPerm = $this->admin([]);
        $this->actingAs($noPerm, 'web')->getJson("/api/v1/users/{$target->uuid}")->assertStatus(403);

        $ok = $this->actingAs($admin, 'web')->getJson("/api/v1/users/{$target->uuid}");
        $ok->assertOk();
        $this->assertArrayNotHasKey('id', $ok->json('data')); // 30

        // 24 unknown UUID -> 404
        $this->actingAs($admin, 'web')->getJson('/api/v1/users/00000000-0000-0000-0000-000000000000')->assertStatus(404);
    }

    public function test_update_rules(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['username' => 'old_name', 'email' => 'old@example.com']);
        $origHash = $target->password;

        // 25 requires user.update
        $noPerm = $this->admin(['user.view']);
        $this->actingAs($noPerm, 'web')->putJson("/api/v1/users/{$target->uuid}", ['username' => 'x'])->assertStatus(403);

        // 26 username/email can be updated
        $this->actingAs($admin, 'web')->putJson("/api/v1/users/{$target->uuid}", [
            'username' => 'new_name', 'email' => 'new@example.com',
        ])->assertOk();
        $target->refresh();
        $this->assertSame('new_name', $target->username);

        // 27-29 generic PUT cannot change password / MFA / failed_login_count
        $this->actingAs($admin, 'web')->putJson("/api/v1/users/{$target->uuid}", ['password' => self::NEW_PASSWORD])->assertStatus(422);
        $this->actingAs($admin, 'web')->putJson("/api/v1/users/{$target->uuid}", ['mfa_enabled' => false])->assertStatus(422);
        $this->actingAs($admin, 'web')->putJson("/api/v1/users/{$target->uuid}", ['failed_login_count' => 3])->assertStatus(422);
        $this->actingAs($admin, 'web')->putJson("/api/v1/users/{$target->uuid}", ['locked_until' => now()->addDay()->toDateTimeString()])->assertStatus(422);

        $target->refresh();
        $this->assertSame($origHash, $target->password); // password unchanged
    }

    // ---- STATUS (31-45) ----

    public function test_status_change_transitions_and_session_revocation(): void
    {
        $admin = $this->admin();

        foreach ([UserStatus::DISABLED, UserStatus::LOCKED, UserStatus::EXPIRED] as $status) {
            $target = User::factory()->create(['status' => UserStatus::ACTIVE, 'password' => 'password']);
            UserSession::create([
                'user_id' => $target->id, 'session_id' => 'sess-'.$target->id,
                'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'login_at' => now(), 'last_activity_at' => now(),
            ]);

            $res = $this->actingAs($admin, 'web')->patchJson("/api/v1/users/{$target->uuid}/status", ['status' => $status->value]);
            $res->assertOk(); // 32/33/34
            $target->refresh();
            $this->assertSame($status, $target->status);
            // 38 LOCKED uses status, not locked_until.
            if ($status === UserStatus::LOCKED) {
                $this->assertNull($target->locked_until);
            }
            // 45 active sessions revoked.
            $this->assertNotNull(UserSession::where('user_id', $target->id)->first()->revoked_at);

            // 35/36/37 cannot authenticate afterward.
            $login = $this->postJson('/api/v1/auth/login', ['username' => $target->username, 'password' => 'password']);
            $this->assertContains($login->status(), [403, 423]);
        }
    }

    public function test_status_requires_permission(): void // 31
    {
        $noPerm = $this->admin(['user.view']);
        $target = User::factory()->create();
        $this->actingAs($noPerm, 'web')->patchJson("/api/v1/users/{$target->uuid}/status", ['status' => 'DISABLED'])->assertStatus(403);
    }

    public function test_self_disable_and_self_lock_rejected(): void // 39-40
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web')->patchJson("/api/v1/users/{$admin->uuid}/status", ['status' => 'DISABLED'])
            ->assertStatus(409)->assertJson(['error' => ['code' => 'USER_SELF_DISABLE_FORBIDDEN']]);
        $this->actingAs($admin, 'web')->patchJson("/api/v1/users/{$admin->uuid}/status", ['status' => 'LOCKED'])
            ->assertStatus(409)->assertJson(['error' => ['code' => 'USER_SELF_DISABLE_FORBIDDEN']]);
    }

    public function test_last_super_admin_cannot_be_made_unusable(): void // 41-43
    {
        $admin = $this->admin(); // separate actor with user.disable
        $superA = $this->superAdmin();
        $superB = $this->superAdmin();

        // Disable one -> ok (still one remaining).
        $this->actingAs($admin, 'web')->patchJson("/api/v1/users/{$superA->uuid}/status", ['status' => 'DISABLED'])->assertOk();

        // Now superB is the last usable SUPER_ADMIN; each unusable transition rejected.
        foreach (['DISABLED', 'LOCKED', 'EXPIRED'] as $status) {
            $this->actingAs($admin, 'web')->patchJson("/api/v1/users/{$superB->uuid}/status", ['status' => $status])
                ->assertStatus(409)->assertJson(['error' => ['code' => 'LAST_SUPER_ADMIN_REQUIRED']]);
        }

        $superB->refresh();
        $this->assertSame(UserStatus::ACTIVE, $superB->status);
    }

    public function test_non_active_session_cannot_continue(): void // 65
    {
        $user = User::factory()->create(['status' => UserStatus::DISABLED]);
        $this->actingAs($user, 'web')->getJson('/api/v1/auth/me')
            ->assertStatus(403)->assertJson(['error' => ['code' => 'ACCOUNT_INACTIVE']]);
    }

    // ---- RESET PASSWORD (46-55) ----

    public function test_reset_password(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['password' => 'password', 'must_change_password' => false]);
        $oldHash = $target->password;
        UserSession::create([
            'user_id' => $target->id, 'session_id' => 'sess-r',
            'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'login_at' => now(), 'last_activity_at' => now(),
        ]);

        // 46 requires user.update
        $noPerm = $this->admin(['user.view']);
        $this->actingAs($noPerm, 'web')->postJson("/api/v1/users/{$target->uuid}/reset-password", ['password' => self::NEW_PASSWORD])->assertStatus(403);

        // 47 password policy applies
        $this->actingAs($admin, 'web')->postJson("/api/v1/users/{$target->uuid}/reset-password", ['password' => 'short'])->assertStatus(422);

        $res = $this->actingAs($admin, 'web')->postJson("/api/v1/users/{$target->uuid}/reset-password", ['password' => self::NEW_PASSWORD]);
        $res->assertOk();

        $target->refresh();
        $this->assertNotSame($oldHash, $target->password); // 48 changed
        $this->assertFalse(Hash::check('password', $target->password)); // 49 old no longer verifies
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $target->password)); // 50 new verifies
        $this->assertTrue($target->must_change_password); // 51
        $this->assertNotNull($target->password_changed_at); // 52
        $this->assertNotNull(UserSession::where('user_id', $target->id)->first()->revoked_at); // 53

        // 54 plaintext not returned.
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $res->getContent());
    }
}
