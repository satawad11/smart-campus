<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AiNode;
use App\Models\AiNodeCommand;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Camera\AiNodeCommandService;
use App\Services\Camera\AiNodeCredentialService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * COM-03: allowlisted, audited, time-limited remote operations on AI nodes.
 */
class AiNodeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
    }

    /** @param  list<string>  $codes */
    private function userWith(array $codes, ?int $siteId = null): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'CMD_'.uniqid(), 'name' => 'Node ops', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'site_id' => $siteId]);

        return $user;
    }

    private function bearer(AiNode $node): array
    {
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function send(User $user, AiNode $node, array $body = ['command' => 'RESYNC_CONFIG'])
    {
        return $this->actingAs($user, 'web')->postJson("/api/v1/ai-nodes/{$node->uuid}/commands", $body);
    }

    public function test_admin_queues_an_allowlisted_command_and_it_is_audited(): void
    {
        $node = AiNode::factory()->create(['status' => DeviceStatus::ACTIVE]);
        $admin = $this->userWith(['ai_node.manage', 'ai_node.view']);

        $res = $this->send($admin, $node, ['command' => 'RESTART_SERVICE', 'reason' => 'stuck stream']);

        $res->assertStatus(201)->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.command', 'RESTART_SERVICE');
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_NODE_COMMAND_REQUESTED', 'entity_id' => $node->uuid]);
        $this->assertNotNull(AiNodeCommand::sole()->expires_at);
    }

    public function test_free_form_or_unknown_commands_are_rejected(): void
    {
        $node = AiNode::factory()->create(['status' => DeviceStatus::ACTIVE]);
        $admin = $this->userWith(['ai_node.manage']);

        foreach (['rm -rf /', 'SHELL', 'restart_service', ''] as $command) {
            $this->send($admin, $node, ['command' => $command])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }
        $this->assertSame(0, AiNodeCommand::count());
    }

    public function test_requires_manage_permission_and_scope(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $nodeB = AiNode::factory()->create(['site_id' => $siteB->id, 'status' => DeviceStatus::ACTIVE]);

        $this->send($this->userWith(['ai_node.view']), $nodeB)->assertStatus(403);
        $this->send($this->userWith(['ai_node.manage', 'ai_node.view'], $siteA->id), $nodeB)->assertStatus(404);
        $this->actingAs($this->userWith(['ai_node.view'], $siteA->id), 'web')
            ->getJson("/api/v1/ai-nodes/{$nodeB->uuid}/commands")->assertStatus(404);
        $this->send($this->userWith(['ai_node.manage'], $siteB->id), $nodeB)->assertStatus(201);
    }

    public function test_inactive_node_and_duplicate_open_command_are_refused(): void
    {
        $admin = $this->userWith(['ai_node.manage']);
        $inactive = AiNode::factory()->create(['status' => DeviceStatus::INACTIVE]);
        $this->send($admin, $inactive)->assertStatus(409)->assertJsonPath('error.code', 'AI_NODE_NOT_ACTIVE');

        $node = AiNode::factory()->create(['status' => DeviceStatus::ACTIVE]);
        $this->send($admin, $node)->assertStatus(201);
        $this->send($admin, $node)->assertStatus(409)->assertJsonPath('error.code', 'AI_NODE_COMMAND_PENDING');
        $this->send($admin, $node, ['command' => 'RESTART_SERVICE'])->assertStatus(201);
    }

    public function test_node_claims_and_reports_its_own_commands(): void
    {
        $node = AiNode::factory()->create(['status' => DeviceStatus::ACTIVE]);
        $this->send($this->userWith(['ai_node.manage']), $node)->assertStatus(201);
        $headers = $this->bearer($node);

        $claimed = $this->postJson('/internal/v1/ai-nodes/commands/claim', [], $headers)->assertOk()->json('data');
        $this->assertCount(1, $claimed);
        $this->assertSame('RESYNC_CONFIG', $claimed[0]['command']);
        $this->assertSame([], $this->postJson('/internal/v1/ai-nodes/commands/claim', [], $headers)->json('data'));

        $this->postJson("/internal/v1/ai-nodes/commands/{$claimed[0]['uuid']}/result", ['status' => 'SUCCEEDED', 'message' => 'synced 2 cameras'], $headers)
            ->assertOk()->assertJsonPath('data.status', 'SUCCEEDED');
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_NODE_COMMAND_COMPLETED', 'entity_id' => $node->uuid]);

        // A result can be reported only once.
        $this->postJson("/internal/v1/ai-nodes/commands/{$claimed[0]['uuid']}/result", ['status' => 'FAILED'], $headers)
            ->assertStatus(409);
    }

    public function test_a_node_cannot_claim_or_answer_another_nodes_commands(): void
    {
        $nodeA = AiNode::factory()->create(['status' => DeviceStatus::ACTIVE]);
        $nodeB = AiNode::factory()->create(['status' => DeviceStatus::ACTIVE]);
        $this->send($this->userWith(['ai_node.manage']), $nodeA)->assertStatus(201);
        $commandUuid = AiNodeCommand::sole()->uuid;
        $headersB = $this->bearer($nodeB);

        $this->assertSame([], $this->postJson('/internal/v1/ai-nodes/commands/claim', [], $headersB)->json('data'));
        $this->postJson("/internal/v1/ai-nodes/commands/{$commandUuid}/result", ['status' => 'SUCCEEDED'], $headersB)
            ->assertStatus(404);
        $this->postJson('/internal/v1/ai-nodes/commands/claim')->assertStatus(401);
    }

    public function test_unfinished_commands_expire(): void
    {
        $node = AiNode::factory()->create(['status' => DeviceStatus::ACTIVE]);
        $this->send($this->userWith(['ai_node.manage']), $node)->assertStatus(201);
        $this->travel(301)->seconds();

        $this->assertSame(1, app(AiNodeCommandService::class)->expireStale());
        $this->assertSame(AiNodeCommand::STATUS_EXPIRED, AiNodeCommand::sole()->status);
        $this->assertSame([], $this->postJson('/internal/v1/ai-nodes/commands/claim', [], $this->bearer($node))->json('data'));
    }

    public function test_history_lists_commands_with_requester(): void
    {
        $node = AiNode::factory()->create(['status' => DeviceStatus::ACTIVE]);
        $admin = $this->userWith(['ai_node.manage', 'ai_node.view']);
        $this->send($admin, $node)->assertStatus(201);

        $this->actingAs($admin, 'web')->getJson("/api/v1/ai-nodes/{$node->uuid}/commands")
            ->assertOk()
            ->assertJsonPath('data.0.command', 'RESYNC_CONFIG')
            ->assertJsonPath('data.0.requested_by.username', $admin->username)
            ->assertJsonMissingPath('data.0.id');
    }
}
