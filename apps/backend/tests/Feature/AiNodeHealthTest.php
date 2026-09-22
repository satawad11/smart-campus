<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\HealthStatus;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AiNode;
use App\Models\AiNodeHealthLog;
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
 * CAM-014 — AI node heartbeat DEGRADED classification, the
 * ai-nodes:detect-stale OFFLINE sweep, and the public
 * GET /ai-nodes/{uuid}/health history endpoint.
 */
class AiNodeHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function tokenFor(AiNode $node): string
    {
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        return $token;
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'AINODEHEALTH_'.uniqid(), 'name' => 'AI Node Health Viewer', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_heartbeat_below_thresholds_classifies_online(): void
    {
        $node = AiNode::factory()->create();
        $token = $this->tokenFor($node);

        $this->postJson('/internal/v1/ai-nodes/heartbeat', [
            'cpu_usage' => 50.0,
            'ram_usage' => 50.0,
        ], $this->bearer($token))->assertOk();

        $log = AiNodeHealthLog::where('ai_node_id', $node->id)->latest('recorded_at')->first();
        $this->assertSame(HealthStatus::ONLINE, $log->status);
    }

    public function test_heartbeat_above_cpu_threshold_classifies_degraded(): void
    {
        $node = AiNode::factory()->create();
        $token = $this->tokenFor($node);

        $this->postJson('/internal/v1/ai-nodes/heartbeat', [
            'cpu_usage' => 95.0,
        ], $this->bearer($token))->assertOk();

        $log = AiNodeHealthLog::where('ai_node_id', $node->id)->latest('recorded_at')->first();
        $this->assertSame(HealthStatus::DEGRADED, $log->status);
    }

    public function test_heartbeat_above_queue_depth_threshold_classifies_degraded(): void
    {
        $node = AiNode::factory()->create();
        $token = $this->tokenFor($node);

        $this->postJson('/internal/v1/ai-nodes/heartbeat', [
            'queue_depth' => 500,
        ], $this->bearer($token))->assertOk();

        $log = AiNodeHealthLog::where('ai_node_id', $node->id)->latest('recorded_at')->first();
        $this->assertSame(HealthStatus::DEGRADED, $log->status);
    }

    public function test_detect_stale_flags_a_node_with_no_recent_heartbeat(): void
    {
        config(['ai_node.health.stale_after_seconds' => 60]);
        $node = AiNode::factory()->create([
            'status' => DeviceStatus::ACTIVE,
            'last_seen_at' => now()->subMinutes(10),
        ]);

        $this->artisan('ai-nodes:detect-stale')->assertExitCode(0);

        $log = AiNodeHealthLog::where('ai_node_id', $node->id)->latest('recorded_at')->first();
        $this->assertNotNull($log);
        $this->assertSame(HealthStatus::OFFLINE, $log->status);
    }

    public function test_detect_stale_ignores_a_recently_seen_node(): void
    {
        config(['ai_node.health.stale_after_seconds' => 90]);
        $node = AiNode::factory()->create([
            'status' => DeviceStatus::ACTIVE,
            'last_seen_at' => now()->subSeconds(10),
        ]);

        $this->artisan('ai-nodes:detect-stale')->assertExitCode(0);

        $this->assertDatabaseCount('ai_node_health_logs', 0);
    }

    public function test_detect_stale_ignores_an_inactive_node(): void
    {
        config(['ai_node.health.stale_after_seconds' => 60]);
        AiNode::factory()->create([
            'status' => DeviceStatus::INACTIVE,
            'last_seen_at' => now()->subDays(1),
        ]);

        $this->artisan('ai-nodes:detect-stale')->assertExitCode(0);

        $this->assertDatabaseCount('ai_node_health_logs', 0);
    }

    public function test_detect_stale_does_not_duplicate_offline_rows_across_runs(): void
    {
        config(['ai_node.health.stale_after_seconds' => 60]);
        AiNode::factory()->create([
            'status' => DeviceStatus::ACTIVE,
            'last_seen_at' => now()->subMinutes(10),
        ]);

        $this->artisan('ai-nodes:detect-stale');
        $this->artisan('ai-nodes:detect-stale');

        $this->assertDatabaseCount('ai_node_health_logs', 1);
    }

    public function test_a_new_heartbeat_after_offline_allows_reflagging_later(): void
    {
        config(['ai_node.health.stale_after_seconds' => 60]);
        $node = AiNode::factory()->create([
            'status' => DeviceStatus::ACTIVE,
            'last_seen_at' => now()->subMinutes(10),
        ]);
        $token = $this->tokenFor($node);

        $this->artisan('ai-nodes:detect-stale');
        $this->assertDatabaseCount('ai_node_health_logs', 1);

        $this->postJson('/internal/v1/ai-nodes/heartbeat', [], $this->bearer($token))->assertOk();
        $this->assertDatabaseCount('ai_node_health_logs', 2);

        $node->refresh();
        $node->last_seen_at = now()->subMinutes(10);
        $node->save();
        $this->artisan('ai-nodes:detect-stale');

        $this->assertDatabaseCount('ai_node_health_logs', 3);
    }

    public function test_get_health_history_requires_permission(): void
    {
        $node = AiNode::factory()->create();
        AiNodeHealthLog::factory()->for($node, 'aiNode')->create();

        $this->getJson("/api/v1/ai-nodes/{$node->uuid}/health")->assertStatus(401);
    }

    public function test_get_health_history_returns_paginated_logs(): void
    {
        $user = $this->userWith(['ai_node.view']);
        $node = AiNode::factory()->create();
        AiNodeHealthLog::factory()->for($node, 'aiNode')->count(3)->create();

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/ai-nodes/{$node->uuid}/health");

        $res->assertOk();
        $res->assertJsonCount(3, 'data');
        $res->assertJsonPath('meta.pagination.total', 3);
    }
}
