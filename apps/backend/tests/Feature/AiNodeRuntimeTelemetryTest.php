<?php

namespace Tests\Feature;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\HealthStatus;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AIModel;
use App\Models\AiNode;
use App\Models\AiNodeHealthLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemAlert;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Camera\AiNodeCredentialService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * COM-03: node heartbeat runtime telemetry (disk, software and model
 * version), model compatibility against the AI model registry, and the
 * resulting alerts.
 */
class AiNodeRuntimeTelemetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
    }

    private function heartbeat(AiNode $node, array $payload)
    {
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        return $this->postJson('/internal/v1/ai-nodes/heartbeat', $payload, ['Authorization' => "Bearer {$token}"]);
    }

    private function viewer(): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'NODEVIEW_'.uniqid(), 'name' => 'Node viewer', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', ['ai_node.view'])->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function registerModel(string $code, string $version, AIModelStatus $status = AIModelStatus::ACTIVE): AIModel
    {
        return AIModel::factory()->create([
            'code' => $code, 'version' => $version,
            'model_type' => AIModelType::FACE_EMBEDDING, 'status' => $status,
        ]);
    }

    public function test_heartbeat_records_disk_and_runtime_versions(): void
    {
        $node = AiNode::factory()->create();

        $this->heartbeat($node, [
            'disk_usage' => 42.5,
            'software_version' => '0.2.0',
            'model_code' => 'scrfd-arcface',
            'model_version' => '2026.1',
        ])->assertOk();

        $node->refresh();
        $this->assertSame('0.2.0', $node->software_version);
        $this->assertSame('scrfd-arcface', $node->model_code);
        $this->assertSame('2026.1', $node->model_version);
        $this->assertEqualsWithDelta(42.5, AiNodeHealthLog::where('ai_node_id', $node->id)->sole()->disk_usage, 0.001);
    }

    public function test_heartbeat_without_runtime_fields_keeps_previous_values(): void
    {
        $node = AiNode::factory()->create(['software_version' => '0.1.0', 'model_code' => 'm', 'model_version' => '1']);

        $this->heartbeat($node, ['cpu_usage' => 10])->assertOk();

        $node->refresh();
        $this->assertSame('0.1.0', $node->software_version);
        $this->assertSame('m', $node->model_code);
    }

    public function test_heartbeat_validates_runtime_fields(): void
    {
        $node = AiNode::factory()->create();

        $this->heartbeat($node, ['disk_usage' => 101])->assertStatus(422);
        $this->heartbeat($node, ['model_code' => str_repeat('x', 65)])->assertStatus(422);
        $this->heartbeat($node, ['software_version' => '<script>'])->assertStatus(422);
    }

    public function test_high_disk_usage_classifies_degraded(): void
    {
        $node = AiNode::factory()->create();

        $this->heartbeat($node, ['disk_usage' => 95])->assertOk();

        $this->assertSame(HealthStatus::DEGRADED, AiNodeHealthLog::where('ai_node_id', $node->id)->sole()->status);
    }

    public function test_resource_reports_model_compatibility(): void
    {
        $this->registerModel('scrfd-arcface', '2026.1');
        $this->registerModel('old-model', '1.0', AIModelStatus::INACTIVE);
        $compatible = AiNode::factory()->create(['model_code' => 'scrfd-arcface', 'model_version' => '2026.1']);
        $retired = AiNode::factory()->create(['model_code' => 'old-model', 'model_version' => '1.0']);
        $unknown = AiNode::factory()->create();

        $viewer = $this->viewer();
        $get = fn (AiNode $n) => $this->actingAs($viewer, 'web')->getJson("/api/v1/ai-nodes/{$n->uuid}")->assertOk();

        $get($compatible)->assertJsonPath('data.model_compatible', true)->assertJsonPath('data.model_code', 'scrfd-arcface');
        $get($retired)->assertJsonPath('data.model_compatible', false);
        $get($unknown)->assertJsonPath('data.model_compatible', null);
    }

    public function test_monitor_alerts_once_for_an_incompatible_node_model(): void
    {
        $this->registerModel('scrfd-arcface', '2026.1');
        AiNode::factory()->create(['model_code' => 'scrfd-arcface', 'model_version' => '2025.9']);
        AiNode::factory()->create(['model_code' => 'scrfd-arcface', 'model_version' => '2026.1']);

        $this->artisan('system:monitor');
        $this->artisan('system:monitor');

        $this->assertSame(1, SystemAlert::where('type', 'AI_NODE_MODEL_MISMATCH')->count());
    }

    public function test_monitor_alerts_on_high_node_disk_usage(): void
    {
        $node = AiNode::factory()->create();
        AiNodeHealthLog::create([
            'ai_node_id' => $node->id, 'disk_usage' => 96.0,
            'status' => HealthStatus::DEGRADED->value, 'recorded_at' => now(),
        ]);

        $this->artisan('system:monitor');

        $this->assertSame(1, SystemAlert::where('type', 'AI_NODE_DISK_HIGH')->count());
    }
}
