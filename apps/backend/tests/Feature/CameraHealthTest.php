<?php

namespace Tests\Feature;

use App\Enums\HealthStatus;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\CameraHealthLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Camera\AiNodeCredentialService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CAM-013 — internal (AI-node-facing) POST /internal/v1/cameras/{uuid}/health
 * and the public GET /cameras/{uuid}/health history endpoint.
 */
class CameraHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
    }

    private function bearerFor(AiNode $node): array
    {
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'CAMHEALTH_'.uniqid(), 'name' => 'Camera Health Viewer', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_requires_ai_node_token(): void
    {
        $camera = Camera::factory()->create();

        $this->postJson("/internal/v1/cameras/{$camera->uuid}/health", [
            'fps' => 15.0,
        ])->assertStatus(401);
    }

    public function test_records_health_log_for_the_assigned_camera(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson(
            "/internal/v1/cameras/{$camera->uuid}/health",
            [
                'fps' => 12.5,
                'latency_ms' => 80,
                'dropped_frames' => 0,
                'resolution' => '1920x1080',
                'brightness_score' => 0.7,
                'blur_score' => 0.8,
                'reconnect_count' => 0,
            ],
        );

        $res->assertOk();
        $res->assertJsonPath('data.status', HealthStatus::ONLINE->value);
        $res->assertJsonPath('data.fps', 12.5);

        $this->assertDatabaseHas('camera_health_logs', [
            'camera_id' => $camera->id,
            'status' => HealthStatus::ONLINE->value,
            'fps' => 12.5,
        ]);

        $camera->refresh();
        $this->assertNotNull($camera->last_seen_at);
    }

    public function test_rejects_a_node_reporting_for_a_camera_it_is_not_assigned_to(): void
    {
        $node = AiNode::factory()->create();
        $otherNode = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $otherNode->id]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson(
            "/internal/v1/cameras/{$camera->uuid}/health",
            ['fps' => 10.0],
        );

        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'CAMERA_NOT_ASSIGNED_TO_NODE');
        $this->assertDatabaseCount('camera_health_logs', 0);
    }

    public function test_unknown_camera_uuid_returns_422(): void
    {
        $node = AiNode::factory()->create();

        $res = $this->withHeaders($this->bearerFor($node))->postJson(
            '/internal/v1/cameras/00000000-0000-0000-0000-000000000000/health',
            ['fps' => 10.0],
        );

        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'CAMERA_NOT_FOUND');
    }

    public function test_client_supplied_status_is_rejected(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson(
            "/internal/v1/cameras/{$camera->uuid}/health",
            ['status' => 'ONLINE', 'fps' => 10.0],
        );

        $res->assertStatus(422);
    }

    public function test_dropped_frames_causes_degraded_classification(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson(
            "/internal/v1/cameras/{$camera->uuid}/health",
            ['fps' => 15.0, 'dropped_frames' => 3],
        );

        $res->assertOk();
        $res->assertJsonPath('data.status', HealthStatus::DEGRADED->value);
    }

    public function test_reconnects_causes_degraded_classification(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson(
            "/internal/v1/cameras/{$camera->uuid}/health",
            ['fps' => 15.0, 'reconnect_count' => 1],
        );

        $res->assertOk();
        $res->assertJsonPath('data.status', HealthStatus::DEGRADED->value);
    }

    public function test_low_fps_causes_degraded_classification(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson(
            "/internal/v1/cameras/{$camera->uuid}/health",
            ['fps' => 1.0],
        );

        $res->assertOk();
        $res->assertJsonPath('data.status', HealthStatus::DEGRADED->value);
    }

    public function test_get_health_history_requires_permission(): void
    {
        $camera = Camera::factory()->create();
        CameraHealthLog::factory()->for($camera)->create();

        $this->getJson("/api/v1/cameras/{$camera->uuid}/health")->assertStatus(401);
    }

    public function test_get_health_history_returns_paginated_logs(): void
    {
        $user = $this->userWith(['camera.view']);
        $camera = Camera::factory()->create();
        CameraHealthLog::factory()->for($camera)->count(3)->create();

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/cameras/{$camera->uuid}/health");

        $res->assertOk();
        $res->assertJsonCount(3, 'data');
        $res->assertJsonPath('meta.pagination.total', 3);
    }
}
