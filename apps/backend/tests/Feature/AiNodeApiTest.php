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
use App\Services\Camera\AiNodeCredentialService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CAM-003/CAM-004 — AI Node registration / heartbeat / per-node auth.
 *
 * Covers the admin CRUD surface (/api/v1/ai-nodes, human session +
 * permission) and the node-facing surface (/internal/v1/ai-nodes/*,
 * per-node bearer credential, never a human session), the "node cannot
 * self-assign scope/status" and "credential cannot impersonate another
 * node" invariants, and health-log telemetry recording. Credential
 * issue/revoke/rotate lifecycle itself is AiNodeCredentialApiTest.
 */
class AiNodeApiTest extends TestCase
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

    private function tokenFor(AiNode $node): string
    {
        [, $plaintext] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        return $plaintext;
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
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
        $node = AiNode::factory()->create();
        $this->tokenFor($node); // ensure a real credential exists in the system

        $this->postJson('/internal/v1/ai-nodes/register')->assertStatus(401);
        $this->postJson('/internal/v1/ai-nodes/register', [], $this->bearer('totally-wrong-token'))->assertStatus(401);
        $this->postJson('/internal/v1/ai-nodes/heartbeat')->assertStatus(401);
    }

    public function test_human_web_session_cannot_substitute_node_token(): void
    {
        $admin = $this->fullAdmin();
        // A fully-authenticated human admin session, without a bearer node
        // credential, must not reach the machine-only endpoint.
        $this->actingAs($admin, 'web')->postJson('/internal/v1/ai-nodes/register')->assertStatus(401);
    }

    public function test_revoked_credential_rejected(): void
    {
        $node = AiNode::factory()->create();
        $service = app(AiNodeCredentialService::class);
        [$credential, $token] = $service->issueCredential($node, null, null);
        $service->revokeCredential($credential, null, null);

        $this->postJson('/internal/v1/ai-nodes/heartbeat', [], $this->bearer($token))->assertStatus(401);
    }

    public function test_disabled_node_credential_rejected(): void
    {
        $node = AiNode::factory()->create(['status' => 'INACTIVE']);
        $token = $this->tokenFor($node);

        $this->postJson('/internal/v1/ai-nodes/heartbeat', [], $this->bearer($token))->assertStatus(401);
    }

    public function test_credential_cannot_impersonate_another_node(): void
    {
        $nodeA = AiNode::factory()->create(['hostname' => 'a.local']);
        $nodeB = AiNode::factory()->create(['hostname' => 'b.local']);
        $tokenA = $this->tokenFor($nodeA);

        // node_code is a prohibited field — identity comes only from the
        // credential, so there is no field through which A's token could
        // ever be used to check in or report telemetry as B.
        $res = $this->postJson('/internal/v1/ai-nodes/register', [
            'hostname' => 'still-a.local',
        ], $this->bearer($tokenA));

        $res->assertOk();
        $this->assertSame('still-a.local', $nodeA->fresh()->hostname);
        $this->assertSame('b.local', $nodeB->fresh()->hostname); // untouched
    }

    // ================= Internal check-in / heartbeat =================

    public function test_checkin_updates_self_reported_fields_and_last_seen(): void
    {
        $node = AiNode::factory()->create(['hostname' => null, 'software_version' => null]);
        $token = $this->tokenFor($node);

        $res = $this->postJson('/internal/v1/ai-nodes/register', [
            'hostname' => 'edge-01.local',
            'software_version' => '2.1.0',
            'capabilities' => ['face_detection' => true],
        ], $this->bearer($token));

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
        $token = $this->tokenFor($node);

        $res = $this->postJson('/internal/v1/ai-nodes/register', [
            'site_uuid' => $otherSite->uuid,
            'status' => 'INACTIVE',
        ], $this->bearer($token));

        $res->assertStatus(422); // prohibited fields rejected outright
        $this->assertNull($node->fresh()->site_id);
        $this->assertSame(DeviceStatus::ACTIVE, $node->fresh()->status);
    }

    public function test_heartbeat_records_telemetry_and_updates_last_seen(): void
    {
        $node = AiNode::factory()->create(['last_seen_at' => null]);
        $token = $this->tokenFor($node);

        $res = $this->postJson('/internal/v1/ai-nodes/heartbeat', [
            'cpu_usage' => 42.5,
            'ram_usage' => 60.0,
            'active_camera_count' => 3,
            'queue_depth' => 1,
        ], $this->bearer($token));

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
        $token = $this->tokenFor($node);
        $this->postJson('/internal/v1/ai-nodes/heartbeat', ['cpu_usage' => 150], $this->bearer($token))->assertStatus(422);
    }

    public function test_heartbeat_does_not_spam_security_audit_log(): void
    {
        $node = AiNode::factory()->create();
        $token = $this->tokenFor($node);
        $before = AuditLog::count();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/internal/v1/ai-nodes/heartbeat', [], $this->bearer($token))->assertOk();
        }

        // Heartbeat is routine telemetry (recorded in ai_node_health_logs),
        // not a security-relevant administrative event.
        $this->assertSame($before, AuditLog::count());
        $this->assertSame(3, AiNodeHealthLog::where('ai_node_id', $node->id)->count());
    }
}
