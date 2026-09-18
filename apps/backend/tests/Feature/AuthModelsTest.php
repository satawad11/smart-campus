<?php

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Enums\UserStatus;
use App\Models\LoginLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\UserRole;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_uuid_is_auto_generated_for_uuid_models(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $session = UserSession::create([
            'user_id' => $user->id,
            'session_id' => 'sess-1',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'login_at' => now(),
            'last_activity_at' => now(),
        ]);

        $this->assertNotEmpty($user->uuid);
        $this->assertNotEmpty($role->uuid);
        $this->assertNotEmpty($session->uuid);
        // BIGINT primary key is preserved (not replaced by uuid).
        $this->assertIsInt($user->id);
        $this->assertTrue($user->getIncrementing());
        $this->assertSame('uuid', $user->getRouteKeyName());
        $this->assertSame('uuid', $role->getRouteKeyName());
    }

    public function test_user_hides_sensitive_fields_and_casts(): void
    {
        $user = User::factory()->create([
            'password' => 'secret-password',
        ]);
        $user->forceFill(['mfa_secret_encrypted' => 'enc', 'remember_token' => 'tok'])->save();

        $array = $user->fresh()->toArray();
        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
        $this->assertArrayNotHasKey('mfa_secret_encrypted', $array);

        // Password stored hashed (not plaintext) and verifiable.
        $this->assertNotSame('secret-password', $user->password);
        $this->assertTrue(Hash::check('secret-password', $user->password));

        // Casts.
        $this->assertInstanceOf(UserStatus::class, $user->status);
        $this->assertSame(UserStatus::ACTIVE, $user->status);
        $this->assertIsBool($user->mfa_enabled);
    }

    public function test_user_soft_deletes(): void
    {
        $user = User::factory()->create();
        $id = $user->id;
        $user->delete();

        $this->assertSoftDeleted('users', ['id' => $id]);
        $this->assertNotNull($user->fresh()->deleted_at);
        $this->assertTrue(User::withTrashed()->whereKey($id)->exists());
    }

    public function test_role_permission_relationships(): void
    {
        $role = Role::factory()->create();
        $perm = Permission::factory()->create();

        $role->permissions()->attach($perm->id);

        $this->assertTrue($role->permissions()->whereKey($perm->id)->exists());
        $this->assertTrue($perm->roles()->whereKey($role->id)->exists());
    }

    public function test_user_role_and_override_relationships(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $perm = Permission::factory()->create();

        $user->roles()->attach($role->id, [
            'valid_from' => now(),
            'valid_until' => now()->addYear(),
        ]);
        $override = $user->permissionOverrides()->create([
            'permission_id' => $perm->id,
            'effect' => PermissionEffect::ALLOW,
        ]);

        $this->assertTrue($user->roles()->whereKey($role->id)->exists());
        $this->assertCount(1, $user->roleAssignments()->get());

        // user_permissions has a composite PK (no surrogate id) — re-read via query.
        $stored = UserPermission::where('user_id', $user->id)
            ->where('permission_id', $perm->id)
            ->first();
        $this->assertNotNull($stored);
        $this->assertInstanceOf(PermissionEffect::class, $stored->effect);
        $this->assertSame(PermissionEffect::ALLOW, $stored->effect);
        $this->assertSame(PermissionEffect::ALLOW, $override->effect);

        // Assignment validity dates cast to Carbon.
        $assignment = $user->roleAssignments()->first();
        $this->assertInstanceOf(Carbon::class, $assignment->valid_from);
        $this->assertInstanceOf(Carbon::class, $assignment->valid_until);
    }

    public function test_user_sessions_and_login_logs_relationships(): void
    {
        $user = User::factory()->create();

        $user->sessions()->create([
            'session_id' => 'sess-x',
            'ip_address' => '10.0.0.1',
            'user_agent' => 'phpunit',
            'login_at' => now(),
            'last_activity_at' => now(),
        ]);
        $log = $user->loginLogs()->create([
            'username' => $user->username,
            'success' => true,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'phpunit',
            'created_at' => now(),
        ]);

        $this->assertCount(1, $user->sessions()->get());
        $this->assertCount(1, $user->loginLogs()->get());
        $this->assertTrue($user->sessions()->first()->user->is($user));
        $this->assertTrue($log->user->is($user));
    }

    public function test_created_at_is_auto_set_without_updated_at(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $perm = Permission::factory()->create();

        // Created through Eloquent WITHOUT supplying created_at.
        $assignment = UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);
        $override = UserPermission::create([
            'user_id' => $user->id,
            'permission_id' => $perm->id,
            'effect' => PermissionEffect::ALLOW,
        ]);
        $log = LoginLog::create([
            'username' => 'auto',
            'success' => true,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);

        // 1-3: created_at is populated automatically.
        $this->assertNotNull($assignment->created_at);
        $this->assertNotNull($override->created_at);
        $this->assertNotNull($log->created_at);

        // 4: no model expects/writes an updated_at column.
        $this->assertNull((new UserRole)->getUpdatedAtColumn());
        $this->assertNull((new UserPermission)->getUpdatedAtColumn());
        $this->assertNull((new LoginLog)->getUpdatedAtColumn());

        // Updating a row must not attempt to write updated_at (would fail on PG).
        $log->update(['failure_reason' => 'note']);
        $this->assertDatabaseHas('login_logs', ['id' => $log->id, 'failure_reason' => 'note']);
    }

    public function test_login_log_tolerates_null_user(): void
    {
        $log = LoginLog::create([
            'user_id' => null,
            'username' => 'unknown',
            'success' => false,
            'failure_reason' => 'INVALID_CREDENTIALS',
            'ip_address' => '10.0.0.1',
            'user_agent' => 'phpunit',
            'created_at' => now(),
        ]);

        $this->assertNull($log->fresh()->user_id);
        $this->assertNull($log->user);
    }
}
