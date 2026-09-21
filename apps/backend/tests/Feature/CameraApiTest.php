<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AiNode;
use App\Models\AuditLog;
use App\Models\Camera;
use App\Models\FaceProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Zone;
use App\Services\Camera\CameraService;
use App\Services\Camera\Connectivity\MockConnectivityProber;
use App\Services\Camera\Snapshot\CameraSnapshotProviderInterface;
use App\Services\Camera\Snapshot\CameraSnapshotResult;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * CAM-002 — Camera CRUD / connection test / snapshot API.
 *
 * Covers auth/permission boundaries, UUID-only public identity, credential
 * encryption/privacy, cross-entity consistency (zone/site, ai_node/site),
 * the three-state credential update contract, connection test, snapshot,
 * and audit integration.
 */
class CameraApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
    }

    /** @param  list<string>  $codes */
    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'CAM_'.uniqid(), 'name' => 'Camera Admin', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function fullAdmin(): User
    {
        return $this->userWith(['camera.view', 'camera.create', 'camera.update', 'camera.enable', 'camera.test', 'camera.live']);
    }

    /** @return array<string, mixed> */
    private function createPayload(Site $site, Zone $zone, array $overrides = []): array
    {
        return array_merge([
            'site_uuid' => $site->uuid,
            'zone_uuid' => $zone->uuid,
            'camera_code' => 'CAM_'.uniqid(),
            'name' => 'Main Gate',
            'rtsp_url' => 'rtsp://192.0.2.10:554/stream1',
            'username' => 'operator',
            'password' => 'super-secret',
        ], $overrides);
    }

    private function makeCamera(?Site $site = null, ?Zone $zone = null): Camera
    {
        $site ??= Site::factory()->create();
        $zone ??= Zone::factory()->for($site)->create();

        return app(CameraService::class)->createCamera($this->createPayload($site, $zone), null, null);
    }

    // ================= Security / routes =================

    public function test_endpoints_require_authentication(): void
    {
        $camera = $this->makeCamera();
        $this->getJson('/api/v1/cameras')->assertStatus(401);
        $this->postJson('/api/v1/cameras', [])->assertStatus(401);
        $this->getJson("/api/v1/cameras/{$camera->uuid}")->assertStatus(401);
        $this->putJson("/api/v1/cameras/{$camera->uuid}", [])->assertStatus(401);
        $this->patchJson("/api/v1/cameras/{$camera->uuid}/status", ['status' => 'INACTIVE'])->assertStatus(401);
        $this->postJson("/api/v1/cameras/{$camera->uuid}/test")->assertStatus(401);
        $this->postJson("/api/v1/cameras/{$camera->uuid}/snapshot")->assertStatus(401);
    }

    public function test_permission_boundaries(): void
    {
        $camera = $this->makeCamera();
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();

        $none = $this->userWith([]);
        $this->actingAs($none, 'web')->getJson('/api/v1/cameras')->assertStatus(403);

        $viewer = $this->userWith(['camera.view']);
        $this->actingAs($viewer, 'web')->getJson('/api/v1/cameras')->assertOk();
        $this->actingAs($viewer, 'web')->postJson('/api/v1/cameras', $this->createPayload($site, $zone))->assertStatus(403);
        $this->actingAs($viewer, 'web')->putJson("/api/v1/cameras/{$camera->uuid}", ['name' => 'X'])->assertStatus(403);
        $this->actingAs($viewer, 'web')->patchJson("/api/v1/cameras/{$camera->uuid}/status", ['status' => 'INACTIVE'])->assertStatus(403);
        $this->actingAs($viewer, 'web')->postJson("/api/v1/cameras/{$camera->uuid}/test")->assertStatus(403);
        $this->actingAs($viewer, 'web')->postJson("/api/v1/cameras/{$camera->uuid}/snapshot")->assertStatus(403);
    }

    public function test_no_delete_route_exists(): void
    {
        $camera = $this->makeCamera();
        $this->actingAs($this->fullAdmin(), 'web')->deleteJson("/api/v1/cameras/{$camera->uuid}")->assertStatus(405);
    }

    // ================= Create =================

    public function test_create_returns_201_and_encrypts_credentials(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $admin = $this->fullAdmin();

        $res = $this->actingAs($admin, 'web')->postJson('/api/v1/cameras', $this->createPayload($site, $zone, ['camera_code' => 'GATE-1']));
        $res->assertStatus(201);
        $res->assertJsonPath('data.has_rtsp_url', true);
        $res->assertJsonPath('data.has_credentials', true);
        $this->assertArrayNotHasKey('rtsp_url_encrypted', $res->json('data'));
        $this->assertArrayNotHasKey('rtsp_url', $res->json('data'));
        $this->assertArrayNotHasKey('password', $res->json('data'));

        $camera = Camera::where('camera_code', 'GATE-1')->firstOrFail();
        $this->assertNotNull($camera->rtsp_url_encrypted);
        $this->assertStringNotContainsString('192.0.2.10', $camera->rtsp_url_encrypted);
        $this->assertSame('rtsp://192.0.2.10:554/stream1', Crypt::decryptString($camera->rtsp_url_encrypted));
    }

    public function test_create_rejects_internal_ids_and_zone_site_mismatch(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $foreignZone = Zone::factory()->for(Site::factory()->create())->create();
        $admin = $this->fullAdmin();

        $this->actingAs($admin, 'web')->postJson('/api/v1/cameras', $this->createPayload($site, $zone, ['site_id' => 1]))->assertStatus(422);
        $this->actingAs($admin, 'web')->postJson('/api/v1/cameras', $this->createPayload($site, $foreignZone))->assertStatus(422);
    }

    public function test_create_ai_node_site_mismatch_rejected(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $otherSite = Site::factory()->create();
        $node = AiNode::factory()->create(['site_id' => $otherSite->id]);

        $this->actingAs($this->fullAdmin(), 'web')->postJson('/api/v1/cameras', $this->createPayload($site, $zone, [
            'ai_node_uuid' => $node->uuid,
        ]))->assertStatus(422);
    }

    public function test_create_ai_node_with_no_fixed_site_is_allowed(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $node = AiNode::factory()->create(['site_id' => null]);

        $this->actingAs($this->fullAdmin(), 'web')->postJson('/api/v1/cameras', $this->createPayload($site, $zone, [
            'ai_node_uuid' => $node->uuid,
        ]))->assertStatus(201)->assertJsonPath('data.ai_node.uuid', $node->uuid);
    }

    public function test_duplicate_camera_code_conflict(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $admin = $this->fullAdmin();

        $this->actingAs($admin, 'web')->postJson('/api/v1/cameras', $this->createPayload($site, $zone, ['camera_code' => 'DUP-1']))->assertStatus(201);
        $this->actingAs($admin, 'web')->postJson('/api/v1/cameras', $this->createPayload($site, $zone, ['camera_code' => 'DUP-1']))->assertStatus(409);
    }

    public function test_camera_created_without_credentials_is_allowed(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();

        $res = $this->actingAs($this->fullAdmin(), 'web')->postJson('/api/v1/cameras', [
            'site_uuid' => $site->uuid, 'zone_uuid' => $zone->uuid, 'camera_code' => 'NOCRED-1', 'name' => 'X',
        ]);
        $res->assertStatus(201);
        $res->assertJsonPath('data.has_rtsp_url', false);
        $res->assertJsonPath('data.has_credentials', false);
    }

    // ================= Update (three-state credential contract) =================

    public function test_update_unchanged_key_absent_leaves_credentials_intact(): void
    {
        $camera = $this->makeCamera();
        $originalEncrypted = $camera->rtsp_url_encrypted;
        $admin = $this->fullAdmin();

        $this->actingAs($admin, 'web')->putJson("/api/v1/cameras/{$camera->uuid}", ['name' => 'Renamed'])->assertOk();

        $this->assertSame($originalEncrypted, $camera->fresh()->rtsp_url_encrypted);
    }

    public function test_update_replacement_value_re_encrypts(): void
    {
        $camera = $this->makeCamera();
        $admin = $this->fullAdmin();

        $this->actingAs($admin, 'web')->putJson("/api/v1/cameras/{$camera->uuid}", [
            'rtsp_url' => 'rtsp://198.51.100.5:554/new',
        ])->assertOk();

        $fresh = $camera->fresh();
        $this->assertSame('rtsp://198.51.100.5:554/new', Crypt::decryptString($fresh->rtsp_url_encrypted));
    }

    public function test_update_explicit_null_clears_credential(): void
    {
        $camera = $this->makeCamera();
        $admin = $this->fullAdmin();

        // has_credentials reflects username presence; clear it explicitly to
        // observe the resource flag flip, while password clears independently.
        $res = $this->actingAs($admin, 'web')->putJson("/api/v1/cameras/{$camera->uuid}", [
            'username' => null, 'password' => null,
        ]);
        $res->assertOk();
        $res->assertJsonPath('data.has_credentials', false);
        $this->assertNull($camera->fresh()->username_encrypted);
        $this->assertNull($camera->fresh()->password_encrypted);
        // Other credentials untouched.
        $this->assertNotNull($camera->fresh()->rtsp_url_encrypted);
    }

    public function test_update_cannot_change_status_code_or_zone(): void
    {
        $camera = $this->makeCamera();
        $admin = $this->fullAdmin();

        foreach ([
            ['status' => 'INACTIVE'],
            ['camera_code' => 'CHANGED'],
            ['zone_uuid' => Zone::factory()->create()->uuid],
            ['site_uuid' => Site::factory()->create()->uuid],
        ] as $bad) {
            $this->actingAs($admin, 'web')->putJson("/api/v1/cameras/{$camera->uuid}", $bad)->assertStatus(422);
        }
    }

    public function test_update_writes_audit_without_credential_values(): void
    {
        $camera = $this->makeCamera();
        $admin = $this->fullAdmin();

        $this->actingAs($admin, 'web')->putJson("/api/v1/cameras/{$camera->uuid}", [
            'name' => 'Renamed', 'password' => 'new-secret-value',
        ])->assertOk();

        $log = AuditLog::where('action', 'CAMERA_UPDATED')->where('entity_id', $camera->uuid)->first();
        $this->assertNotNull($log);
        $this->assertTrue($log->new_values['password_changed']);
        $payload = json_encode($log->new_values).json_encode($log->old_values);
        $this->assertStringNotContainsString('new-secret-value', $payload);
    }

    // ================= Status =================

    public function test_status_change_enable_disable(): void
    {
        $camera = $this->makeCamera();
        $admin = $this->fullAdmin();

        $this->actingAs($admin, 'web')->patchJson("/api/v1/cameras/{$camera->uuid}/status", ['status' => 'INACTIVE'])
            ->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->assertDatabaseHas('cameras', ['id' => $camera->id, 'status' => 'INACTIVE']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CAMERA_STATUS_CHANGED', 'entity_id' => $camera->uuid]);
    }

    // ================= Connection test =================

    public function test_connection_test_reachable(): void
    {
        $camera = $this->makeCamera();
        $service = app(CameraService::class);
        $service->setConnectivityProber(new MockConnectivityProber(reachable: true));
        $this->app->instance(CameraService::class, $service);

        $res = $this->actingAs($this->fullAdmin(), 'web')->postJson("/api/v1/cameras/{$camera->uuid}/test");
        $res->assertOk();
        $this->assertTrue($res->json('data.reachable'));
        $this->assertIsFloat($res->json('data.latency_ms'));
    }

    public function test_connection_test_unreachable_returns_sanitized_error(): void
    {
        $camera = $this->makeCamera();
        $service = app(CameraService::class);
        $service->setConnectivityProber(new MockConnectivityProber(reachable: false, failureCode: 'CAMERA_CONNECTION_TIMEOUT'));
        $this->app->instance(CameraService::class, $service);

        $res = $this->actingAs($this->fullAdmin(), 'web')->postJson("/api/v1/cameras/{$camera->uuid}/test");
        $res->assertOk();
        $this->assertFalse($res->json('data.reachable'));
        $this->assertSame('CAMERA_CONNECTION_TIMEOUT', $res->json('data.error_code'));
        // No raw credential/URL ever leaks into the response.
        $this->assertStringNotContainsString('192.0.2.10', $res->getContent());
        $this->assertStringNotContainsString('operator', $res->getContent());
    }

    public function test_connection_test_audits_without_credentials(): void
    {
        $camera = $this->makeCamera();
        $this->actingAs($this->fullAdmin(), 'web')->postJson("/api/v1/cameras/{$camera->uuid}/test")->assertOk();

        $log = AuditLog::where('action', 'CAMERA_CONNECTION_TESTED')->where('entity_id', $camera->uuid)->first();
        $this->assertNotNull($log);
        $payload = json_encode($log->new_values);
        $this->assertStringNotContainsString('192.0.2.10', $payload);
        $this->assertStringNotContainsString('operator', $payload);
        $this->assertStringNotContainsString('super-secret', $payload);
    }

    // ================= Snapshot =================

    public function test_snapshot_returns_mock_image_and_never_enrolls(): void
    {
        $camera = $this->makeCamera();
        $res = $this->actingAs($this->fullAdmin(), 'web')->postJson("/api/v1/cameras/{$camera->uuid}/snapshot");

        $res->assertOk();
        $this->assertSame('image/png', $res->json('data.content_type'));
        $this->assertSame('MOCK', $res->json('data.source'));
        $this->assertNotEmpty($res->json('data.image_base64'));

        // Never persists a biometric/face record as a side effect.
        $this->assertSame(0, FaceProfile::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'CAMERA_SNAPSHOT_CAPTURED', 'entity_id' => $camera->uuid]);
    }

    public function test_snapshot_failure_returns_422_without_leaking_internals(): void
    {
        $camera = $this->makeCamera();
        $service = app(CameraService::class);
        $service->setSnapshotProvider(new class implements CameraSnapshotProviderInterface
        {
            public function capture($camera, int $timeoutSeconds): CameraSnapshotResult
            {
                return CameraSnapshotResult::failure('CAMERA_SNAPSHOT_TIMEOUT');
            }
        });
        $this->app->instance(CameraService::class, $service);

        $res = $this->actingAs($this->fullAdmin(), 'web')->postJson("/api/v1/cameras/{$camera->uuid}/snapshot");
        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'CAMERA_SNAPSHOT_TIMEOUT');
    }

    // ================= List / filter =================

    public function test_list_pagination_and_filters(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        app(CameraService::class)->createCamera($this->createPayload($site, $zone, ['camera_code' => 'FIND-1']), null, null);
        $admin = $this->fullAdmin();

        $res = $this->actingAs($admin, 'web')->getJson('/api/v1/cameras');
        $res->assertOk();
        $this->assertSame(20, $res->json('meta.pagination.per_page'));
        foreach ($res->json('data') as $row) {
            $this->assertArrayNotHasKey('id', $row);
            $this->assertArrayNotHasKey('rtsp_url_encrypted', $row);
        }

        $bySite = $this->actingAs($admin, 'web')->getJson("/api/v1/cameras?site_uuid={$site->uuid}");
        foreach ($bySite->json('data') as $row) {
            $this->assertSame($site->uuid, $row['site']['uuid']);
        }

        $this->actingAs($admin, 'web')->getJson('/api/v1/cameras?sort=id')->assertStatus(422);
    }
}
