<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AUTH-001 migration/schema tests.
 *
 * Runs against the active connection: SQLite locally, and real PostgreSQL in
 * scripts/verify-auth.sh. Checks that would behave materially differently on
 * SQLite (enum CHECK rejection) are guarded to the pgsql driver.
 */
class AuthSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function newUser(array $overrides = []): array
    {
        $data = array_merge([
            'uuid' => (string) Str::uuid(),
            'username' => 'user_'.Str::random(8),
            'password' => 'hashed-placeholder',
        ], $overrides);
        DB::table('users')->insert($data);

        return $data;
    }

    private function newRole(array $overrides = []): int
    {
        return DB::table('roles')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'code' => 'ROLE_'.Str::random(6),
            'name' => 'Role',
        ], $overrides));
    }

    private function newPermission(array $overrides = []): int
    {
        return DB::table('permissions')->insertGetId(array_merge([
            'code' => 'module.'.Str::random(6),
            'module' => 'module',
            'name' => 'Permission',
        ], $overrides));
    }

    /** 1. Required AUTH tables exist. */
    public function test_required_tables_exist(): void
    {
        foreach ([
            'users', 'roles', 'permissions', 'role_permissions',
            'user_roles', 'user_permissions', 'user_sessions', 'login_logs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing table: {$table}");
        }
    }

    /** 2. Required columns exist. */
    public function test_users_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'id', 'uuid', 'person_id', 'username', 'email', 'password', 'status',
            'mfa_enabled', 'mfa_secret_encrypted', 'failed_login_count',
            'locked_until', 'last_login_at', 'password_changed_at',
            'must_change_password', 'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    /** 3. users.uuid uniqueness. */
    public function test_users_uuid_is_unique(): void
    {
        $uuid = (string) Str::uuid();
        $this->newUser(['uuid' => $uuid]);
        $this->expectException(QueryException::class);
        $this->newUser(['uuid' => $uuid]);
    }

    /** 4. users.username uniqueness. */
    public function test_users_username_is_unique(): void
    {
        $this->newUser(['username' => 'dupe']);
        $this->expectException(QueryException::class);
        $this->newUser(['username' => 'dupe']);
    }

    /** 5. nullable email, unique when populated. */
    public function test_email_is_nullable_but_unique_when_present(): void
    {
        // Two NULL emails are allowed.
        $this->newUser(['email' => null]);
        $this->newUser(['email' => null]);

        $this->newUser(['email' => 'a@example.com']);
        $this->expectException(QueryException::class);
        $this->newUser(['email' => 'a@example.com']);
    }

    /** 6. role code uniqueness. */
    public function test_role_code_is_unique(): void
    {
        $this->newRole(['code' => 'SUPER_ADMIN']);
        $this->expectException(QueryException::class);
        $this->newRole(['code' => 'SUPER_ADMIN']);
    }

    /** 7. permission code uniqueness. */
    public function test_permission_code_is_unique(): void
    {
        $this->newPermission(['code' => 'user.create']);
        $this->expectException(QueryException::class);
        $this->newPermission(['code' => 'user.create']);
    }

    /** 8. role-permission duplicate prevention (composite PK). */
    public function test_role_permission_prevents_duplicates(): void
    {
        $roleId = $this->newRole();
        $permId = $this->newPermission();
        DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);

        $this->expectException(QueryException::class);
        DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
    }

    /** 9. user_roles supports validity dates + nullable scope columns. */
    public function test_user_roles_supports_validity_and_scope(): void
    {
        $this->assertTrue(Schema::hasColumns('user_roles', [
            'user_id', 'role_id', 'site_id', 'zone_id', 'valid_from', 'valid_until', 'created_at',
        ]));

        $user = $this->newUser();
        $userId = DB::table('users')->where('uuid', $user['uuid'])->value('id');
        $roleId = $this->newRole();

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'site_id' => null,
            'zone_id' => null,
            'valid_from' => now(),
            'valid_until' => now()->addYear(),
            'created_at' => now(),
        ]);

        $this->assertDatabaseCount('user_roles', 1);
    }

    /** 10. user_permissions effect accepts ALLOW/DENY (and rejects others on pgsql). */
    public function test_user_permission_effect_domain(): void
    {
        $user = $this->newUser();
        $userId = DB::table('users')->where('uuid', $user['uuid'])->value('id');
        $allow = $this->newPermission();
        $deny = $this->newPermission();

        DB::table('user_permissions')->insert(['user_id' => $userId, 'permission_id' => $allow, 'effect' => 'ALLOW']);
        DB::table('user_permissions')->insert(['user_id' => $userId, 'permission_id' => $deny, 'effect' => 'DENY']);
        $this->assertDatabaseCount('user_permissions', 2);

        if (! $this->isPgsql()) {
            $this->markTestIncomplete('enum CHECK rejection is only enforced on PostgreSQL');
        }

        $bad = $this->newPermission();
        $this->expectException(QueryException::class);
        DB::table('user_permissions')->insert(['user_id' => $userId, 'permission_id' => $bad, 'effect' => 'MAYBE']);
    }

    /** 11. user_sessions uuid uniqueness. */
    public function test_user_session_uuid_is_unique(): void
    {
        $user = $this->newUser();
        $userId = DB::table('users')->where('uuid', $user['uuid'])->value('id');
        $uuid = (string) Str::uuid();

        $row = [
            'user_id' => $userId,
            'session_id' => Str::random(20),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'login_at' => now(),
            'last_activity_at' => now(),
        ];
        DB::table('user_sessions')->insert(['uuid' => $uuid] + $row);

        $this->expectException(QueryException::class);
        DB::table('user_sessions')->insert(['uuid' => $uuid] + $row);
    }

    /** 12. login_logs allows nullable user_id. */
    public function test_login_log_allows_null_user_id(): void
    {
        DB::table('login_logs')->insert([
            'user_id' => null,
            'username' => 'ghost',
            'success' => false,
            'failure_reason' => 'INVALID_CREDENTIALS',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'created_at' => now(),
        ]);

        $this->assertDatabaseCount('login_logs', 1);
    }

    /** 13. foreign keys between AUTH tables behave (invalid ref rejected). */
    public function test_foreign_keys_are_enforced(): void
    {
        $this->expectException(QueryException::class);
        DB::table('role_permissions')->insert(['role_id' => 999999, 'permission_id' => 888888]);
    }

    // Clean-database migrate (#14) and rollback/fresh (#15) are verified against
    // real PostgreSQL by scripts/verify-auth.sh (run outside a DB transaction,
    // which RefreshDatabase + in-memory SQLite cannot host — e.g. VACUUM).
}
