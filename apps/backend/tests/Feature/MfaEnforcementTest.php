<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security baseline (decision 2026-10-14): SUPER_ADMIN and SYSTEM_ADMIN must
 * enrol MFA before using permission-protected APIs; enrollment itself stays
 * reachable. Runs with the production default role list.
 */
class MfaEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
        config(['auth_security.mfa_required_roles' => 'SUPER_ADMIN,SYSTEM_ADMIN']);
    }

    private function userWithRole(string $role, bool $mfa = false): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE, 'mfa_enabled' => $mfa]);
        UserRole::create(['user_id' => $user->id, 'role_id' => Role::where('code', $role)->value('id')]);

        return $user;
    }

    public function test_admin_without_mfa_is_refused_until_enrolled(): void
    {
        foreach (['SUPER_ADMIN', 'SYSTEM_ADMIN'] as $role) {
            $admin = $this->userWithRole($role);

            $this->actingAs($admin, 'web')->getJson('/api/v1/users')
                ->assertStatus(403)->assertJsonPath('error.code', 'MFA_ENROLLMENT_REQUIRED');
        }
    }

    public function test_enrollment_and_profile_stay_reachable_and_flag_the_requirement(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');

        $this->actingAs($admin, 'web')->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.user.mfa_enrollment_required', true);
        $this->actingAs($admin, 'web')->postJson('/api/v1/auth/mfa/setup')->assertOk();
    }

    public function test_admin_with_mfa_has_normal_access(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', mfa: true);

        $this->actingAs($admin, 'web')->getJson('/api/v1/users')->assertOk();
        $this->actingAs($admin, 'web')->getJson('/api/v1/auth/me')->assertJsonPath('data.user.mfa_enrollment_required', false);
    }

    public function test_other_roles_are_not_required_to_enrol(): void
    {
        $officer = $this->userWithRole('SECURITY_OFFICER');

        $this->actingAs($officer, 'web')->getJson('/api/v1/dashboard/summary')->assertOk();
    }

    public function test_realtime_channel_is_refused_until_enrolled(): void
    {
        // Register the channel on a real (reverb) broadcaster, as BroadcastChannelAuthTest does;
        // the phpunit default null driver authorises everything.
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
        $site = Site::factory()->create();
        $body = ['socket_id' => '1234.5678', 'channel_name' => "private-site.{$site->uuid}"];

        $this->actingAs($this->userWithRole('SUPER_ADMIN'), 'web')->postJson('/api/v1/broadcasting/auth', $body)
            ->assertStatus(403);
        $this->actingAs($this->userWithRole('SUPER_ADMIN', mfa: true), 'web')->postJson('/api/v1/broadcasting/auth', $body)
            ->assertOk();
    }

    public function test_empty_role_list_disables_enforcement(): void
    {
        config(['auth_security.mfa_required_roles' => '']);

        $this->actingAs($this->userWithRole('SUPER_ADMIN'), 'web')->getJson('/api/v1/users')->assertOk();
    }
}
