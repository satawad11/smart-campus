<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AiNode;
use App\Models\AiNodeConfigRevision;
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
 * COM-03: versioned, validated node runtime settings with append-only
 * rollback, delivered with the node configuration and acknowledged by the
 * node's heartbeat.
 */
class AiNodeConfigRevisionTest extends TestCase
{
    use RefreshDatabase;

    private AiNode $node;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
        $this->node = AiNode::factory()->create(['status' => DeviceStatus::ACTIVE]);
    }

    /** @param  list<string>  $codes */
    private function userWith(array $codes, ?int $siteId = null): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'CFG_'.uniqid(), 'name' => 'Node config', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'site_id' => $siteId]);

        return $user;
    }

    private function manager(): User
    {
        return $this->userWith(['ai_node.manage', 'ai_node.view']);
    }

    private function save(User $user, array $settings, ?AiNode $node = null)
    {
        $node ??= $this->node;

        return $this->actingAs($user, 'web')->postJson("/api/v1/ai-nodes/{$node->uuid}/config-revisions", ['settings' => $settings, 'note' => 'tune']);
    }

    private function bearer(): array
    {
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($this->node, null, null);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_each_save_creates_a_numbered_audited_revision(): void
    {
        $manager = $this->manager();

        $this->save($manager, ['target_fps' => 5])->assertStatus(201)->assertJsonPath('data.revision', 1);
        $this->save($manager, ['target_fps' => 8, 'heartbeat_interval_seconds' => 20])->assertStatus(201)->assertJsonPath('data.revision', 2);

        $this->assertSame(2, AiNodeConfigRevision::count());
        $this->assertSame(2, AuditLog::where('action', 'AI_NODE_CONFIG_REVISED')->count());
    }

    public function test_settings_are_validated_against_the_schema(): void
    {
        $manager = $this->manager();

        foreach ([
            ['target_fps' => 0],
            ['target_fps' => 31],
            ['target_fps' => '5'],
            ['shell' => 'reboot'],
            [],
        ] as $settings) {
            $res = $this->save($manager, $settings);
            $this->assertContains($res->status(), [422]);
        }
        $this->assertSame(0, AiNodeConfigRevision::count());
    }

    public function test_rollback_appends_a_copy_of_the_older_revision(): void
    {
        $manager = $this->manager();
        $this->save($manager, ['target_fps' => 5]);
        $this->save($manager, ['target_fps' => 12]);

        $this->actingAs($manager, 'web')->postJson("/api/v1/ai-nodes/{$this->node->uuid}/config-revisions/1/rollback")
            ->assertStatus(201)
            ->assertJsonPath('data.revision', 3)
            ->assertJsonPath('data.settings.target_fps', 5)
            ->assertJsonPath('data.rolled_back_from_revision', 1);

        $this->assertSame(3, AiNodeConfigRevision::count()); // history kept
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_NODE_CONFIG_ROLLED_BACK']);
        $this->actingAs($manager, 'web')->postJson("/api/v1/ai-nodes/{$this->node->uuid}/config-revisions/99/rollback")
            ->assertStatus(404);
    }

    public function test_permissions_and_scope(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $nodeB = AiNode::factory()->create(['site_id' => $siteB->id, 'status' => DeviceStatus::ACTIVE]);

        $this->save($this->userWith(['ai_node.view']), ['target_fps' => 5], $nodeB)->assertStatus(403);
        $this->save($this->userWith(['ai_node.manage'], $siteA->id), ['target_fps' => 5], $nodeB)->assertStatus(404);
        $this->actingAs($this->userWith(['ai_node.view'], $siteA->id), 'web')
            ->getJson("/api/v1/ai-nodes/{$nodeB->uuid}/config-revisions")->assertStatus(404);
        $this->save($this->userWith(['ai_node.manage'], $siteB->id), ['target_fps' => 5], $nodeB)->assertStatus(201);
    }

    public function test_node_receives_latest_runtime_settings_and_acknowledges_them(): void
    {
        $headers = $this->bearer();
        $this->getJson("/internal/v1/ai-nodes/{$this->node->uuid}/config", $headers)
            ->assertOk()->assertJsonPath('data.runtime', null);

        $this->save($this->manager(), ['target_fps' => 7]);
        $this->getJson("/internal/v1/ai-nodes/{$this->node->uuid}/config", $headers)
            ->assertOk()
            ->assertJsonPath('data.runtime.revision', 1)
            ->assertJsonPath('data.runtime.settings.target_fps', 7);

        $this->postJson('/internal/v1/ai-nodes/heartbeat', ['config_revision' => 1], $headers)->assertOk();
        $this->assertSame(1, $this->node->fresh()->applied_config_revision);

        $this->actingAs($this->manager(), 'web')->getJson("/api/v1/ai-nodes/{$this->node->uuid}/config-revisions")
            ->assertOk()
            ->assertJsonPath('data.applied_revision', 1)
            ->assertJsonPath('data.revisions.0.revision', 1)
            ->assertJsonPath('data.schema.target_fps.max', 30);
    }
}
