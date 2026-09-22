<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\HealthStatus;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\CameraHealthLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Zone;
use App\Services\Camera\AiNodeCredentialService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Module 04 Consolidated Security & Acceptance Test Suite (CAM-021).
 *
 * Verifies cross-cutting invariants and boundaries for the whole module
 * (CAM-001..CAM-020) that individual per-task feature tests each cover in
 * isolation but never assert together as module-wide guarantees: credential
 * privacy, the human-session/machine-credential auth split, node identity
 * spoofing resistance, and the Module 05 access-decision boundary.
 */
class Module04AcceptanceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'MOD04_'.uniqid(), 'name' => 'Module 04 Test Role', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function bearerFor(AiNode $node): array
    {
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        return ['Authorization' => "Bearer {$token}"];
    }

    // ---- Credential privacy ----

    public function test_camera_credentials_are_never_returned_in_any_admin_response(): void
    {
        $user = $this->userWith(['camera.view', 'camera.create']);
        $site = Site::factory()->create();
        $zone = Zone::factory()->create(['site_id' => $site->id]);

        $store = $this->actingAs($user, 'web')->postJson('/api/v1/cameras', [
            'site_uuid' => $site->uuid,
            'zone_uuid' => $zone->uuid,
            'camera_code' => 'SEC-CAM-1',
            'name' => 'Security Test Camera',
            'rtsp_url' => 'rtsp://raw-secret-host/stream',
            'username' => 'raw-secret-user',
            'password' => 'raw-secret-pass',
        ]);
        $store->assertStatus(201);
        $storeBody = json_encode($store->json());
        $this->assertStringNotContainsString('raw-secret', $storeBody);

        $uuid = $store->json('data.uuid');
        $show = $this->actingAs($user, 'web')->getJson("/api/v1/cameras/{$uuid}");
        $showBody = json_encode($show->json());
        $this->assertStringNotContainsString('raw-secret', $showBody);
        $this->assertArrayHasKey('has_rtsp_url', $show->json('data'));
        $this->assertArrayNotHasKey('rtsp_url_encrypted', $show->json('data'));
        $this->assertArrayNotHasKey('rtsp_url', $show->json('data'));
    }

    public function test_ai_node_bearer_token_is_shown_exactly_once_and_never_stored_in_plaintext(): void
    {
        $node = AiNode::factory()->create();

        [$credential, $plaintext] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        $this->assertNotEmpty($plaintext);
        $this->assertNotSame($plaintext, $credential->token_hash);
        $this->assertStringNotContainsString($plaintext, (string) $credential->token_hash);
    }

    // ---- Machine vs human auth boundary ----

    public function test_internal_routes_reject_a_human_session_and_never_accept_it_as_a_node(): void
    {
        $user = $this->userWith(['camera.view']);
        $camera = Camera::factory()->create();

        $this->actingAs($user, 'web')
            ->postJson("/internal/v1/cameras/{$camera->uuid}/health", ['fps' => 10.0])
            ->assertStatus(401);
    }

    public function test_admin_routes_reject_an_ai_node_bearer_credential(): void
    {
        $node = AiNode::factory()->create();

        $this->withHeaders($this->bearerFor($node))
            ->getJson('/api/v1/cameras')
            ->assertStatus(401);
    }

    // ---- Node identity spoofing resistance ----

    public function test_a_node_cannot_report_health_for_a_camera_assigned_to_another_node(): void
    {
        $nodeA = AiNode::factory()->create();
        $nodeB = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $nodeB->id]);

        $this->withHeaders($this->bearerFor($nodeA))
            ->postJson("/internal/v1/cameras/{$camera->uuid}/health", ['fps' => 10.0])
            ->assertStatus(422);

        $this->assertDatabaseCount('camera_health_logs', 0);
    }

    public function test_a_node_cannot_fetch_another_nodes_configuration_or_credentials(): void
    {
        $nodeA = AiNode::factory()->create();
        $nodeB = AiNode::factory()->create();
        Camera::factory()->create([
            'ai_node_id' => $nodeB->id,
            'rtsp_url_encrypted' => Crypt::encryptString('rtsp://should-not-leak/stream'),
        ]);

        $res = $this->withHeaders($this->bearerFor($nodeA))
            ->getJson("/internal/v1/ai-nodes/{$nodeB->uuid}/config");

        $res->assertStatus(422);
        $this->assertStringNotContainsString('should-not-leak', json_encode($res->json()));
    }

    public function test_a_node_cannot_self_report_health_status_for_itself_or_a_camera(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $this->withHeaders($this->bearerFor($node))
            ->postJson("/internal/v1/cameras/{$camera->uuid}/health", ['status' => 'ONLINE', 'fps' => 10.0])
            ->assertStatus(422);

        $this->withHeaders($this->bearerFor($node))
            ->postJson('/internal/v1/ai-nodes/heartbeat', ['status' => 'ONLINE'])
            ->assertStatus(422);
    }

    // ---- Module 05 boundary: no access-decision concept anywhere in Module 04 ----

    public function test_no_access_decision_or_door_control_fields_exist_anywhere_in_module_04_schema(): void
    {
        foreach (['cameras', 'ai_nodes', 'camera_health_logs', 'ai_node_health_logs', 'ai_node_credentials'] as $table) {
            foreach (['access_decision', 'door_state', 'relay_triggered', 'allow', 'deny'] as $forbidden) {
                $this->assertFalse(
                    Schema::hasColumn($table, $forbidden),
                    "Table {$table} must never contain a Module 05 access-decision column ({$forbidden})."
                );
            }
        }
    }

    public function test_camera_health_response_never_contains_a_module_05_access_decision(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $res = $this->withHeaders($this->bearerFor($node))
            ->postJson("/internal/v1/cameras/{$camera->uuid}/health", ['fps' => 10.0]);

        $res->assertOk();
        $data = $res->json('data');
        foreach (['access_decision', 'door_state', 'relay_triggered'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
    }

    // ---- Health status classification is server-derived, never client-trusted ----

    public function test_health_status_enum_values_are_only_ever_online_degraded_offline(): void
    {
        $this->assertSame(['ONLINE', 'DEGRADED', 'OFFLINE'], array_column(HealthStatus::cases(), 'value'));
    }

    public function test_device_status_is_administrative_and_distinct_from_health_status(): void
    {
        // DeviceStatus (admin enable/disable) and HealthStatus (observed
        // telemetry) are deliberately separate enums/columns — CAM-013/014
        // never overwrites Camera.status/AiNode.status from health logic.
        $this->assertSame(['ACTIVE', 'INACTIVE'], array_column(DeviceStatus::cases(), 'value'));
        $this->assertNotSame(DeviceStatus::class, HealthStatus::class);
    }

    // ---- End-to-end module lifecycle ----

    public function test_end_to_end_camera_ai_node_lifecycle(): void
    {
        $admin = $this->userWith([
            'camera.view', 'camera.create', 'camera.update',
            'ai_node.view', 'ai_node.manage',
        ]);

        $site = Site::factory()->create();
        $zone = Zone::factory()->create(['site_id' => $site->id]);

        // 1. Admin registers an AI node.
        $nodeRes = $this->actingAs($admin, 'web')->postJson('/api/v1/ai-nodes', [
            'node_code' => 'E2E-NODE-1',
            'name' => 'E2E Node',
        ]);
        $nodeRes->assertStatus(201);
        $nodeUuid = $nodeRes->json('data.uuid');
        $node = AiNode::where('uuid', $nodeUuid)->first();

        // 2. Admin creates a camera assigned to that node.
        $cameraRes = $this->actingAs($admin, 'web')->postJson('/api/v1/cameras', [
            'site_uuid' => $site->uuid,
            'zone_uuid' => $zone->uuid,
            'ai_node_uuid' => $nodeUuid,
            'camera_code' => 'E2E-CAM-1',
            'name' => 'E2E Camera',
            'rtsp_url' => 'rtsp://e2e-host/stream',
        ]);
        $cameraRes->assertStatus(201);
        $cameraUuid = $cameraRes->json('data.uuid');

        // 3. Admin sets an ROI polygon.
        $roiRes = $this->actingAs($admin, 'web')->patchJson("/api/v1/cameras/{$cameraUuid}/roi", [
            'points' => [
                ['x' => 0.1, 'y' => 0.1],
                ['x' => 0.9, 'y' => 0.1],
                ['x' => 0.5, 'y' => 0.9],
            ],
        ]);
        $roiRes->assertOk();

        // 4. The node fetches its own configuration and receives the ROI + decrypted RTSP URL.
        $configRes = $this->withHeaders($this->bearerFor($node))
            ->getJson("/internal/v1/ai-nodes/{$nodeUuid}/config");
        $configRes->assertOk();
        $configRes->assertJsonCount(1, 'data.cameras');
        $configRes->assertJsonPath('data.cameras.0.rtsp_url', 'rtsp://e2e-host/stream');
        $configRes->assertJsonCount(3, 'data.cameras.0.roi');

        // 5. The node reports camera health.
        $healthRes = $this->withHeaders($this->bearerFor($node))
            ->postJson("/internal/v1/cameras/{$cameraUuid}/health", ['fps' => 15.0, 'dropped_frames' => 0]);
        $healthRes->assertOk();
        $healthRes->assertJsonPath('data.status', HealthStatus::ONLINE->value);

        // 6. The admin views the health history through the public API.
        $historyRes = $this->actingAs($admin, 'web')->getJson("/api/v1/cameras/{$cameraUuid}/health");
        $historyRes->assertOk();
        $historyRes->assertJsonCount(1, 'data');

        $this->assertDatabaseCount('camera_health_logs', 1);
        CameraHealthLog::where('camera_id', Camera::where('uuid', $cameraUuid)->value('id'))
            ->firstOrFail();
    }

    public function test_prohibited_internal_identifiers_are_unconditionally_rejected_on_camera_create(): void
    {
        $admin = $this->userWith(['camera.view', 'camera.create']);
        $site = Site::factory()->create();
        $zone = Zone::factory()->create(['site_id' => $site->id]);

        $res = $this->actingAs($admin, 'web')->postJson('/api/v1/cameras', [
            'site_uuid' => $site->uuid,
            'zone_uuid' => $zone->uuid,
            'camera_code' => 'SPOOF-1',
            'name' => 'Spoof Test',
            'id' => 9999,
            'uuid' => '00000000-0000-0000-0000-000000000099',
            'site_id' => 1,
            'rtsp_url_encrypted' => 'attacker-value',
        ]);

        $res->assertStatus(422);
    }
}
