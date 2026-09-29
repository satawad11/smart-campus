<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\SystemSettingHistory;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-001: Central system settings service & API tests.
 */
class SystemSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create([
            'code' => 'ROLE_'.uniqid(),
            'name' => 'Setting Admin',
            'system_role' => false,
            'status' => RoleStatus::ACTIVE,
        ]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_get_settings_requires_authentication(): void
    {
        $this->getJson('/api/v1/settings')->assertStatus(401);
    }

    public function test_get_settings_requires_system_view_permission(): void
    {
        $user = $this->userWith([]);
        $this->actingAs($user, 'web')->getJson('/api/v1/settings')->assertStatus(403);
    }

    public function test_get_settings_returns_all_grouped_settings(): void
    {
        $user = $this->userWith(['system.view']);
        $res = $this->actingAs($user, 'web')->getJson('/api/v1/settings');

        $res->assertOk();
        $res->assertJsonStructure([
            'success',
            'data' => [
                'GENERAL',
                'SECURITY',
                'FACE',
                'EVENT',
                'CAMERA',
                'STORAGE',
                'REPORT',
                'NOTIFICATION',
                'RETENTION',
                'FEATURE',
            ],
        ]);
        $siteSetting = collect($res->json('data.GENERAL'))->firstWhere('key', 'general.site_name');
        $this->assertNotNull($siteSetting);
        $this->assertSame('AI Smart Campus Security Platform', $siteSetting['value']);
    }

    public function test_get_group_settings(): void
    {
        $user = $this->userWith(['system.view']);
        $res = $this->actingAs($user, 'web')->getJson('/api/v1/settings/security');

        $res->assertOk();
        $this->assertNotEmpty($res->json('data'));
        $keys = collect($res->json('data'))->pluck('key')->all();
        $this->assertContains('security.admin_mfa_enforced', $keys);
    }

    public function test_update_settings_requires_system_manage_permission(): void
    {
        $user = $this->userWith(['system.view']);
        $res = $this->actingAs($user, 'web')->putJson('/api/v1/settings/security', [
            'settings' => ['security.session_timeout_minutes' => 60],
        ]);

        $res->assertStatus(403);
    }

    public function test_update_settings_validates_typed_values(): void
    {
        $user = $this->userWith(['system.manage']);

        // Invalid integer
        $res = $this->actingAs($user, 'web')->putJson('/api/v1/settings/security', [
            'settings' => ['security.session_timeout_minutes' => 'invalid-integer'],
        ]);
        $res->assertStatus(422);

        // Invalid float
        $resFloat = $this->actingAs($user, 'web')->putJson('/api/v1/settings/face', [
            'settings' => ['face.match_threshold' => 'not-a-float'],
        ]);
        $resFloat->assertStatus(422);
    }

    public function test_update_settings_persists_records_history_and_audits(): void
    {
        $user = $this->userWith(['system.manage']);

        $res = $this->actingAs($user, 'web')->putJson('/api/v1/settings/security', [
            'settings' => [
                'security.session_timeout_minutes' => 180,
                'security.admin_mfa_enforced' => true,
            ],
        ]);

        $res->assertOk();

        // Verify persisted setting
        $setting = SystemSetting::where('key', 'security.session_timeout_minutes')->first();
        $this->assertSame(180, $setting->getTypedValue());

        // Verify history record
        $history = SystemSettingHistory::where('key', 'security.session_timeout_minutes')->latest('id')->first();
        $this->assertNotNull($history);
        $this->assertSame('120', $history->old_value);
        $this->assertSame('180', $history->new_value);
        $this->assertSame($user->id, $history->user_id);

        // Verify audit log
        $audit = AuditLog::where('action', AuditAction::SETTING_UPDATED->value)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('SECURITY', $audit->entity_id);
    }

    public function test_public_settings_accessible_without_auth(): void
    {
        $res = $this->getJson('/api/v1/settings/public');
        $res->assertOk();

        $data = $res->json('data');
        $this->assertArrayHasKey('general.site_name', $data);
        $this->assertArrayHasKey('general.timezone', $data);
        // Non-public settings must NOT be in public endpoint
        $this->assertArrayNotHasKey('security.admin_mfa_enforced', $data);
        $this->assertArrayNotHasKey('face.match_threshold', $data);
    }
}
