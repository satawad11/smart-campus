<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AiNode;
use App\Models\AiNodeHealthLog;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CAM-003 — AI Node registration / heartbeat.
 *
 * Covers the admin CRUD surface (/api/v1/ai-nodes, human session +
 * permission) and the node-facing surface (/internal/v1/ai-nodes/*,
 * internal.token, never a human session), the "node cannot self-assign
 * scope/status" invariant, unknown-node_code rejection, and health-log
 * telemetry recording.
 */
class AiNodeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
        config(['services.internal.token' => 'test-internal-token']);
    }

    /** @param  list<string>  $codes */
    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'NODE_'.uniqid(), 'name' => 'Node Admin', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function fullAdmin(): User
    {
        return $this->userWith(['ai_node.view', 'ai_node.manage']);
    }

    private function internalHeaders(string $token = 'test-internal-token'): array
    {
        return ['X-Internal-Token' => $token];
    }

    // ================= Admin CRUD security =================

    public function test_admin_endpoints_require_authentication(): void
    {
        $node = AiNode::factory()->create();
        $this->getJson('/api/v1/ai-nodes')->assertStatus(401);
        $this->postJson('/api/v1/ai-nodes', [])->assertStatus(401);
        $this->getJson("/api/v1/ai-nodes/{$node->uuid}")->assertStatus(401);
        $this->putJson("/api/v1/ai-nodes/{$node->uuid}", [])->assertStatus(401);
        $this->patchJson("/api/v1/ai-nodes/{$node->uuid}/status", ['status' => 'INACTIVE'])->assertStatus(401);
    }

    public function test_admin_permission_boundaries(): void
    {
        $viewer = $this->userWith(['ai_node.view']);
        $none = $this->userWith([]);
        $node = AiNode::factory()->create();

        $this->actingAs($none, 'web')->getJson('/api/v1/ai-nodes')->assertStatus(403);
        $this->actingAs($viewer, 'web')->getJson('/api/v1/ai-nodes')->assertOk();
        $this->actingAs($viewer, 'web')->postJson('/api/v1/ai-nodes', ['node_code' => 'X', 'name' => 'X'])->assertStatus(403);
        $this->actingAs($viewer, 'web')->putJson("/api/v1/ai-nodes/{$node->uuid}", ['name' => 'Y'])->assertStatus(403);
        $this->actingAs($viewer, 'web')->patchJson("/api/v1/ai-nodes/{$node->uuid}/status", ['status' => 'INACTIVE'])->assertStatus(403);
    }

    public function test_no_delete_route_exists(): void
    {
        $node = AiNode::factory()->create();
        $this->actingAs($this->fullAdmin(), 'web')->deleteJson("/api/v1/ai-nodes/{$node->uuid}")->assertStatus(405);
    }

    // ================= Admin CRUD =================

    public function test_register_node_without_site(): void
    {
        $admin = $this->fullAdmin();
        $res = $this->actingAs($admin, 'web')->postJson('/api/v1/ai-nodes', [
            'node_code' => 'NODE-A', 'name' => 'Edge Node A',
        ]);
        $res->assertStatus(201);
        $res->assertJsonPath('data.node_code', 'NODE-A');
        $res->assertJsonPath('data.site', null);
        $this->assertArrayNotHasKey('id', $res->json('data'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_NODE_REGISTERED']);
    }

    public function test_register_node_with_site(): void
    {
        $site = Site::factory()->create();
        $res = $this->actingAs($this->fullAdmin(), 'web')->postJson('/api/v1/ai-nodes', [
            'node_code' => 'NODE-B', 'name' => 'Edge Node B', 'site_uuid' => $site->uuid,
        ]);
        $res->assertStatus(201)->assertJsonPath('data.site.uuid', $site->uuid);
    }

    public function test_duplicate_node_code_conflict(): void
    {
        $admin = $this->fullAdmin();
        $this->actingAs($admin, 'web')->postJson('/api/v1/ai-nodes', ['node_code' => 'DUP', 'name' => 'X'])->assertStatus(201);
        $this->actingAs($admin, 'web')->postJson('/api/v1/ai-nodes', ['node_code' => 'DUP', 'name' => 'Y'])->assertStatus(409);
    }

    public function test_create_rejects_self_reported_and_internal_fields(): void
    {
        $admin = $this->fullAdmin();
        foreach ([
            ['software_version' => '1.0.0'],
            ['capabilities' => ['face' => true]],
            ['id' => 1],
            ['uuid' => 'x'],
        ] as $bad) {
            $this->actingAs($admin, 'web')->postJson('/api/v1/ai-nodes', array_merge(['node_code' => 'X'.uniqid(), 'name' => 'X'], $bad))
                ->assertStatus(422);
        }
    }

    public function test_update_node_and_reassign_site(): void
    {
        $node = AiNode::factory()->create(['site_id' => null]);
        $site = Site::factory()->create();
        $admin = $this->fullAdmin();

        $res = $this->actingAs($admin, 'web')->putJson("/api/v1/ai-nodes/{$node->uuid}", [
            'name' => 'Renamed', 'site_uuid' => $site->uuid,
        ]);
        $res->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.site.uuid', $site->uuid);
    }

    public function test_update_cannot_change_status_or_node_code(): void
    {
        $node = AiNode::factory()->create();
        $admin = $this->fullAdmin();
        $this->actingAs($admin, 'web')->putJson("/api/v1/ai-nodes/{$node->uuid}", ['status' => 'INACTIVE'])->assertStatus(422);
        $this->actingAs($admin, 'web')->putJson("/api/v1/ai-nodes/{$node->uuid}", ['node_code' => 'CHANGED'])->assertStatus(422);
    }

    public function test_status_change(): void
    {
        $node = AiNode::factory()->create();
        $admin = $this->fullAdmin();

        $this->actingAs($admin, 'web')->patchJson("/api/v1/ai-nodes/{$node->uuid}/status", ['status' => 'INACTIVE'])
            ->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_NODE_STATUS_CHANGED', 'entity_id' => $node->uuid]);
    }

    // ================= Internal (node-facing) security =================

    public function test_internal_routes_reject_missing_or_wrong_token(): void
    {
        $this->postJson('/internal/v1/ai-nodes/register', ['node_code' => 'X'])->assertStatus(401);
        $this->postJson('/internal/v1/ai-nodes/register', ['node_code' => 'X'], $this->internalHeaders('wrong-token'))->assertStatus(401);
        $this->postJson('/internal/v1/ai-nodes/heartbeat', ['node_code' => 'X'])->assertStatus(401);
    }

    public function test_internal_routes_fail_closed_when_token_unconfigured(): void
    {
        config(['services.internal.token' => null]);
        $this->postJson('/internal/v1/ai-nodes/register', ['node_code' => 'X'], $this->internalHeaders())->assertStatus(503);
    }

    public function test_human_web_session_cannot_substitute_internal_token(): void
    {
        $admin = $this->fullAdmin();
        // Even a fully-authenticated human admin session, without the
        // internal token header, must not reach the machine-only endpoint.
        $this->actingAs($admin, 'web')->postJson('/internal/v1/ai-nodes/register', ['node_code' => 'X'])->assertStatus(401);
    }

    // ================= Internal check-in / heartbeat =================

    public function test_checkin_unknown_node_code_rejected(): void
    {
        $res = $this->postJson('/internal/v1/ai-nodes/register', ['node_code' => 'NEVER-PROVISIONED'], $this->internalHeaders());
        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'AI_NODE_NOT_REGISTERED');
    }

    public function test_checkin_updates_self_reported_fields_and_last_seen(): void
    {
        $node = AiNode::factory()->create(['hostname' => null, 'software_version' => null]);

        $res = $this->postJson('/internal/v1/ai-nodes/register', [
            'node_code' => $node->node_code,
            'hostname' => 'edge-01.local',
            'software_version' => '2.1.0',
            'capabilities' => ['face_detection' => true],
        ], $this->internalHeaders());

        $res->assertOk();
        $fresh = $node->fresh();
        $this->assertSame('edge-01.local', $fresh->hostname);
        $this->assertSame('2.1.0', $fresh->software_version);
        $this->assertNotNull($fresh->last_seen_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_NODE_CHECKED_IN', 'entity_id' => $node->uuid]);
    }

    public function test_checkin_cannot_reassign_site_or_status(): void
    {
        $node = AiNode::factory()->create(['site_id' => null, 'status' => 'ACTIVE']);
        $otherSite = Site::factory()->create();

        $res = $this->postJson('/internal/v1/ai-nodes/register', [
            'node_code' => $node->node_code,
            'site_uuid' => $otherSite->uuid,
            'status' => 'INACTIVE',
        ], $this->internalHeaders());

        $res->assertStatus(422); // prohibited fields rejected outright
        $this->assertNull($node->fresh()->site_id);
        $this->assertSame(DeviceStatus::ACTIVE, $node->fresh()->status);
    }

    public function test_heartbeat_unknown_node_rejected(): void
    {
        $this->postJson('/internal/v1/ai-nodes/heartbeat', ['node_code' => 'GHOST'], $this->internalHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'AI_NODE_NOT_REGISTERED');
    }

    public function test_heartbeat_records_telemetry_and_updates_last_seen(): void
    {
        $node = AiNode::factory()->create(['last_seen_at' => null]);

        $res = $this->postJson('/internal/v1/ai-nodes/heartbeat', [
            'node_code' => $node->node_code,
            'cpu_usage' => 42.5,
            'ram_usage' => 60.0,
            'active_camera_count' => 3,
            'queue_depth' => 1,
        ], $this->internalHeaders());

        $res->assertOk();
        $this->assertNotNull($node->fresh()->last_seen_at);

        $log = AiNodeHealthLog::where('ai_node_id', $node->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(42.5, $log->cpu_usage);
        $this->assertSame('ONLINE', $log->status->value);
    }

    public function test_heartbeat_bounds_out_of_range_values(): void
    {
        $node = AiNode::factory()->create();
        $this->postJson('/internal/v1/ai-nodes/heartbeat', [
            'node_code' => $node->node_code, 'cpu_usage' => 150,
        ], $this->internalHeaders())->assertStatus(422);
    }

    public function test_heartbeat_does_not_spam_security_audit_log(): void
    {
        $node = AiNode::factory()->create();
        $before = AuditLog::count();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/internal/v1/ai-nodes/heartbeat', ['node_code' => $node->node_code], $this->internalHeaders())->assertOk();
        }

        // Heartbeat is routine telemetry (recorded in ai_node_health_logs),
        // not a security-relevant administrative event.
        $this->assertSame($before, AuditLog::count());
        $this->assertSame(3, AiNodeHealthLog::where('ai_node_id', $node->id)->count());
    }
}
