<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\System\FeatureFlagService;
use App\Services\System\SystemSettingService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PILOT-001: FEATURE_*_ENABLED environment kill switches (SEC-024 condition 1)
 * must hard-disable locked V1 features even when the system setting is enabled.
 */
class FeatureKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    private function activeUser(): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create([
            'code' => 'ROLE_'.uniqid(),
            'name' => 'Operator',
            'system_role' => false,
            'status' => RoleStatus::ACTIVE,
        ]);
        $role->permissions()->attach(Permission::pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_explicit_false_kill_switch_overrides_enabled_setting(): void
    {
        config(['features.locked_kill_switches.door' => false]);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.door' => true]);

        $flags = app(FeatureFlagService::class);
        $this->assertTrue($flags->isKillSwitchEngaged('door'));
        $this->assertFalse($flags->isEnabled('door'));

        $this->actingAs($this->activeUser(), 'web')
            ->getJson('/api/v1/doors')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_DISABLED');

        $this->getJson('/api/v1/features')->assertOk()->assertJsonPath('data.door', false);
    }

    public function test_every_locked_feature_honours_its_kill_switch(): void
    {
        $settings = app(SystemSettingService::class);
        $flags = app(FeatureFlagService::class);

        foreach (FeatureFlagService::LOCKED_V1_FEATURES as $feature) {
            config(['features.locked_kill_switches.'.$feature => 'false']);
            $settings->updateGroup('FEATURE', ['feature.'.$feature => true]);

            $this->assertFalse($flags->isEnabled($feature), "{$feature} must stay disabled");
            $this->assertFalse($flags->getAll()[$feature], "{$feature} must report disabled");
        }
    }

    public function test_unparseable_kill_switch_value_fails_closed(): void
    {
        config(['features.locked_kill_switches.alpr' => 'maybe']);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.alpr' => true]);

        $this->assertFalse(app(FeatureFlagService::class)->isEnabled('alpr'));
    }

    public function test_unset_kill_switch_keeps_settings_behaviour(): void
    {
        config(['features.locked_kill_switches.door' => null]);
        $flags = app(FeatureFlagService::class);

        $this->assertFalse($flags->isEnabled('door'));
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.door' => true]);
        $this->assertFalse($flags->isKillSwitchEngaged('door'));
        $this->assertTrue($flags->isEnabled('door'));
    }

    public function test_kill_switches_do_not_affect_non_locked_features(): void
    {
        $flags = app(FeatureFlagService::class);

        $this->assertFalse($flags->isKillSwitchEngaged('realtime_monitor'));
        $this->assertFalse($flags->isKillSwitchEngaged('scheduled_reports'));
    }
}
