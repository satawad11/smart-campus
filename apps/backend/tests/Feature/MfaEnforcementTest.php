<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\Site;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\System\SystemSettingService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin MFA enforcement: OFF by default (owner decision 2026-10-14); an
 * administrator switches it on in System Settings -> Security
 * (security.admin_mfa_enforced). When on, SUPER_ADMIN and SYSTEM_ADMIN must
 * enrol MFA before using permission-protected APIs; enrollment stays reachable.
 */
class MfaEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class, SystemSettingSeeder::class]);
    }

    private function userWithRole(string $role, bool $mfa = false): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE, 'mfa_enabled' => $mfa]);
        UserRole::create(['user_id' => $user->id, 'role_id' => Role::where('code', $role)->value('id')]);

        return $user;
    }

    private function enforce(bool $on = true): void
    {
        app(SystemSettingService::class)->updateGroup('SECURITY', ['security.admin_mfa_enforced' => $on]);
    }

    public function test_enforcement_is_off_by_default(): void
    {
        $this->assertFalse((bool) app(SystemSettingService::class)->get('security.admin_mfa_enforced'));

        $this->actingAs($this->userWithRole('SUPER_ADMIN'), 'web')->getJson('/api/v1/users')->assertOk();
        $this->actingAs($this->userWithRole('SUPER_ADMIN'), 'web')->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.user.mfa_enrollment_required', false);
    }

    public function test_an_administrator_can_switch_it_on_in_system_settings(): void
    {
        $enrolledAdmin = $this->userWithRole('SUPER_ADMIN', mfa: true);

        $this->actingAs($enrolledAdmin, 'web')
            ->putJson('/api/v1/settings/SECURITY', ['settings' => ['security.admin_mfa_enforced' => true]])
            ->assertOk();

        $this->actingAs($this->userWithRole('SYSTEM_ADMIN'), 'web')->getJson('/api/v1/users')
            ->assertStatus(403)->assertJsonPath('error.code', 'MFA_ENROLLMENT_REQUIRED');
        $this->actingAs($enrolledAdmin, 'web')->getJson('/api/v1/users')->assertOk();
    }

    public function test_when_on_admins_without_mfa_are_refused_but_can_enrol(): void
    {
        $this->enforce();
        $admin = $this->userWithRole('SUPER_ADMIN');

        $this->actingAs($admin, 'web')->getJson('/api/v1/users')
            ->assertStatus(403)->assertJsonPath('error.code', 'MFA_ENROLLMENT_REQUIRED');
        $this->actingAs($admin, 'web')->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.user.mfa_enrollment_required', true);
        $this->actingAs($admin, 'web')->postJson('/api/v1/auth/mfa/setup')->assertOk();
    }

    public function test_when_on_other_roles_are_not_required_to_enrol(): void
    {
        $this->enforce();

        $this->actingAs($this->userWithRole('SECURITY_OFFICER'), 'web')->getJson('/api/v1/dashboard/summary')->assertOk();
    }

    public function test_when_on_the_realtime_channel_is_refused_until_enrolled(): void
    {
        $this->enforce();
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
        $site = Site::factory()->create();
        $body = ['socket_id' => '1234.5678', 'channel_name' => "private-site.{$site->uuid}"];

        $this->actingAs($this->userWithRole('SUPER_ADMIN'), 'web')->postJson('/api/v1/broadcasting/auth', $body)
            ->assertStatus(403);
        $this->actingAs($this->userWithRole('SUPER_ADMIN', mfa: true), 'web')->postJson('/api/v1/broadcasting/auth', $body)
            ->assertOk();
    }

    public function test_switching_it_off_again_restores_access(): void
    {
        $this->enforce();
        $admin = $this->userWithRole('SUPER_ADMIN');
        $this->actingAs($admin, 'web')->getJson('/api/v1/users')->assertStatus(403);

        $this->enforce(false);

        $this->actingAs($admin, 'web')->getJson('/api/v1/users')->assertOk();
    }

    public function test_reseeding_keeps_the_administrators_choice_but_relocks_future_features(): void
    {
        $this->enforce();
        SystemSetting::where('key', 'feature.door')->update(['value' => 'true']);

        $this->seed(SystemSettingSeeder::class);

        $this->assertSame('true', SystemSetting::where('key', 'security.admin_mfa_enforced')->value('value'));
        $this->assertSame('false', SystemSetting::where('key', 'feature.door')->value('value'));
    }
}
