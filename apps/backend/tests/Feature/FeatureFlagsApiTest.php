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
 * SEC-002: Backend-enforced feature flags API & gate middleware tests.
 */
class FeatureFlagsApiTest extends TestCase
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

    public function test_get_features_returns_feature_flag_map(): void
    {
        $res = $this->getJson('/api/v1/features');

        $res->assertOk();
        $flags = $res->json('data');

        $this->assertIsArray($flags);
        $this->assertFalse($flags['door'], 'door feature must be false in Production V1');
        $this->assertFalse($flags['visitor'], 'visitor feature must be false in Production V1');
        $this->assertFalse($flags['vehicle'], 'vehicle feature must be false in Production V1');
        $this->assertFalse($flags['alpr'], 'alpr feature must be false in Production V1');
        $this->assertFalse($flags['ai_assistant'], 'ai_assistant feature must be false in Production V1');
    }

    public function test_locked_features_fail_closed_with_403_feature_disabled(): void
    {
        $user = $this->activeUser();

        // Attempting to access locked doors route
        $resDoor = $this->actingAs($user, 'web')->getJson('/api/v1/doors');
        $resDoor->assertStatus(403);
        $resDoor->assertJsonPath('error_code', 'FEATURE_DISABLED');
        $this->assertSame('door', $resDoor->json('feature'));

        // Attempting to access locked visitors route
        $resVisitor = $this->actingAs($user, 'web')->getJson('/api/v1/visitors');
        $resVisitor->assertStatus(403);
        $resVisitor->assertJsonPath('error_code', 'FEATURE_DISABLED');

        // Attempting to access locked vehicle route
        $resVehicle = $this->actingAs($user, 'web')->getJson('/api/v1/vehicles');
        $resVehicle->assertStatus(403);
        $resVehicle->assertJsonPath('error_code', 'FEATURE_DISABLED');

        // Attempting to access locked alpr route
        $resAlpr = $this->actingAs($user, 'web')->getJson('/api/v1/alpr');
        $resAlpr->assertStatus(403);
        $resAlpr->assertJsonPath('error_code', 'FEATURE_DISABLED');

        // Attempting to access locked ai-assistant route
        $resAi = $this->actingAs($user, 'web')->getJson('/api/v1/ai-assistant');
        $resAi->assertStatus(403);
        $resAi->assertJsonPath('error_code', 'FEATURE_DISABLED');
    }

    public function test_feature_gate_opens_when_setting_enabled_and_closes_when_disabled(): void
    {
        $user = $this->activeUser();
        $settingService = app(SystemSettingService::class);
        $flagService = app(FeatureFlagService::class);

        // Initially disabled
        $this->assertFalse($flagService->isEnabled('door'));
        $this->actingAs($user, 'web')->getJson('/api/v1/doors')->assertStatus(403);

        // Enable feature in settings
        $settingService->updateGroup('FEATURE', ['feature.door' => true]);
        $this->assertTrue($flagService->isEnabled('door'));

        // Now accessible
        $resOpen = $this->actingAs($user, 'web')->getJson('/api/v1/doors');
        $resOpen->assertOk();

        // Disable feature again (fails closed)
        $settingService->updateGroup('FEATURE', ['feature.door' => false]);
        $this->assertFalse($flagService->isEnabled('door'));
        $this->actingAs($user, 'web')->getJson('/api/v1/doors')->assertStatus(403);
    }
}
