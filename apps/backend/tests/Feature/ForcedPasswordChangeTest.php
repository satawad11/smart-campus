<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V1 audit A-04: must_change_password was enforced only by the frontend router;
 * the backend must refuse permission-protected actions until the change is done.
 */
class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function privilegedUser(bool $mustChange): User
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'password' => 'Original-Password-123',
            'must_change_password' => $mustChange,
        ]);
        $role = Role::create(['code' => 'ALL_'.uniqid(), 'name' => 'All', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_permission_protected_routes_are_refused_until_password_changed(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $user = $this->privilegedUser(mustChange: true);

        $this->actingAs($user, 'web')->getJson('/api/v1/users')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
        $this->actingAs($user, 'web')->getJson('/api/v1/auth/me')->assertOk();

        $this->actingAs($user, 'web')->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Original-Password-123',
            'password' => 'Brand-New-Password-456',
            'password_confirmation' => 'Brand-New-Password-456',
        ])->assertOk();

        $this->actingAs($user->fresh(), 'web')->getJson('/api/v1/users')->assertOk();
    }

    public function test_users_without_forced_change_are_unaffected(): void
    {
        $user = $this->privilegedUser(mustChange: false);

        $this->actingAs($user, 'web')->getJson('/api/v1/users')->assertOk();
    }
}
