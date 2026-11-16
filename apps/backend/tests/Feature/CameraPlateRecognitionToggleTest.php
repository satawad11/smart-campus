<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AiNode;
use App\Models\AuditLog;
use App\Models\Camera;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Zone;
use App\Services\Camera\AiNodeCredentialService;
use App\Services\System\SystemSettingService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VEH-005 (ACR-VEH-01 D1): per-camera plate-reading switch. It can only be turned on while
 * feature.alpr is on, and nodes receive the effective value (switch AND feature.alpr).
 */
class CameraPlateRecognitionToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        config(['features.locked_kill_switches.alpr' => null]);
    }

    private function setAlpr(bool $on): void
    {
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.alpr' => $on]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'CAM_'.uniqid(), 'name' => 'Camera Admin', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', ['camera.view', 'camera.create', 'camera.update'])->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();

        return array_merge([
            'site_uuid' => $site->uuid,
            'zone_uuid' => $zone->uuid,
            'camera_code' => 'CAM_'.uniqid(),
            'name' => 'ประตูรถเข้า',
        ], $overrides);
    }

    private function configFor(AiNode $node): array
    {
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        return $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/internal/v1/ai-nodes/{$node->uuid}/config")
            ->assertOk()
            ->json('data');
    }

    public function test_existing_and_new_cameras_default_to_off(): void
    {
        $res = $this->actingAs($this->admin(), 'web')->postJson('/api/v1/cameras', $this->payload())->assertCreated();

        $res->assertJsonPath('data.plate_recognition_enabled', false);
        $this->assertFalse(Camera::factory()->create()->refresh()->plate_recognition_enabled);
    }

    public function test_cannot_turn_on_while_alpr_is_disabled(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'web')
            ->postJson('/api/v1/cameras', $this->payload(['plate_recognition_enabled' => true]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ALPR_DISABLED');

        $camera = Camera::factory()->create();
        $this->actingAs($admin, 'web')
            ->putJson("/api/v1/cameras/{$camera->uuid}", ['plate_recognition_enabled' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ALPR_DISABLED');

        // Turning it off is always allowed.
        $this->actingAs($admin, 'web')
            ->putJson("/api/v1/cameras/{$camera->uuid}", ['plate_recognition_enabled' => false])
            ->assertOk();
    }

    public function test_can_turn_on_when_alpr_is_enabled_and_change_is_audited(): void
    {
        $this->setAlpr(true);
        $camera = Camera::factory()->create();

        $this->actingAs($this->admin(), 'web')
            ->putJson("/api/v1/cameras/{$camera->uuid}", ['plate_recognition_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.plate_recognition_enabled', true);

        $this->assertTrue($camera->refresh()->plate_recognition_enabled);
        $log = AuditLog::where('action', 'CAMERA_UPDATED')->latest('id')->firstOrFail();
        $this->assertTrue($log->new_values['plate_recognition_enabled']);
    }

    public function test_node_receives_effective_value_switch_and_system_flag(): void
    {
        $node = AiNode::factory()->create();
        $on = Camera::factory()->create(['ai_node_id' => $node->id, 'plate_recognition_enabled' => true]);
        $off = Camera::factory()->create(['ai_node_id' => $node->id, 'plate_recognition_enabled' => false]);

        $byUuid = fn (array $config) => collect($config['cameras'])->keyBy('camera_uuid');

        // ALPR off: nobody reads plates, even the camera whose switch is on.
        $cameras = $byUuid($this->configFor($node));
        $this->assertFalse($cameras[$on->uuid]['plate_recognition_enabled']);
        $this->assertFalse($cameras[$off->uuid]['plate_recognition_enabled']);

        // ALPR on: only the switched-on camera.
        $this->setAlpr(true);
        $cameras = $byUuid($this->configFor($node));
        $this->assertTrue($cameras[$on->uuid]['plate_recognition_enabled']);
        $this->assertFalse($cameras[$off->uuid]['plate_recognition_enabled']);

        // Kill switch wins over the setting.
        config(['features.locked_kill_switches.alpr' => false]);
        $cameras = $byUuid($this->configFor($node));
        $this->assertFalse($cameras[$on->uuid]['plate_recognition_enabled']);
    }
}
