<?php

namespace Tests\Feature;

use App\Enums\AiNodeCredentialStatus;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AiNode;
use App\Models\AiNodeCredential;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CAM-004 — AI node credential issue/rotate/revoke lifecycle (admin API).
 *
 * Covers permission boundaries, the one-time plaintext-token reveal, hashed
 * server storage (never plaintext at rest), rotation (issuing revokes the
 * prior active credential), revoke, and cross-node revoke protection.
 */
class AiNodeCredentialApiTest extends TestCase
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
        $role = Role::create(['code' => 'CRED_'.uniqid(), 'name' => 'Credential Admin', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
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

    public function test_endpoints_require_authentication_and_permission(): void
    {
        $node = AiNode::factory()->create();
        $this->getJson("/api/v1/ai-nodes/{$node->uuid}/credentials")->assertStatus(401);
        $this->postJson("/api/v1/ai-nodes/{$node->uuid}/credentials")->assertStatus(401);

        $viewer = $this->userWith(['ai_node.view']);
        $this->actingAs($viewer, 'web')->postJson("/api/v1/ai-nodes/{$node->uuid}/credentials")->assertStatus(403);
    }

    public function test_issue_returns_plaintext_token_once_and_stores_only_a_hash(): void
    {
        $node = AiNode::factory()->create();
        $admin = $this->fullAdmin();

        $res = $this->actingAs($admin, 'web')->postJson("/api/v1/ai-nodes/{$node->uuid}/credentials");
        $res->assertStatus(201);
        $token = $res->json('data.token');
        $this->assertNotEmpty($token);
        $this->assertSame('ACTIVE', $res->json('data.status'));

        $credential = AiNodeCredential::where('ai_node_id', $node->id)->firstOrFail();
        // Stored value is a sha256 hash, never the plaintext token.
        $this->assertSame(hash('sha256', $token), $credential->token_hash);
        $this->assertStringNotContainsString($token, (string) $credential->token_hash);

        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_NODE_CREDENTIAL_ISSUED', 'entity_id' => $node->uuid]);
    }

    public function test_list_never_exposes_token_or_hash(): void
    {
        $node = AiNode::factory()->create();
        $admin = $this->fullAdmin();
        $this->actingAs($admin, 'web')->postJson("/api/v1/ai-nodes/{$node->uuid}/credentials");

        $res = $this->actingAs($admin, 'web')->getJson("/api/v1/ai-nodes/{$node->uuid}/credentials");
        $res->assertOk();
        foreach ($res->json('data') as $row) {
            $this->assertArrayNotHasKey('token', $row);
            $this->assertArrayNotHasKey('token_hash', $row);
            $this->assertArrayNotHasKey('id', $row);
            $this->assertArrayNotHasKey('ai_node_id', $row);
        }
    }

    public function test_issuing_again_rotates_and_revokes_the_previous_credential(): void
    {
        $node = AiNode::factory()->create();
        $admin = $this->fullAdmin();

        $first = $this->actingAs($admin, 'web')->postJson("/api/v1/ai-nodes/{$node->uuid}/credentials")->json('data');
        $second = $this->actingAs($admin, 'web')->postJson("/api/v1/ai-nodes/{$node->uuid}/credentials")->json('data');

        $this->assertSame(AiNodeCredentialStatus::REVOKED, AiNodeCredential::where('uuid', $first['uuid'])->value('status'));
        $this->assertSame(AiNodeCredentialStatus::ACTIVE, AiNodeCredential::where('uuid', $second['uuid'])->value('status'));

        // Old token no longer authenticates.
        $this->postJson('/internal/v1/ai-nodes/heartbeat', [], ['Authorization' => "Bearer {$first['token']}"])->assertStatus(401);
        $this->postJson('/internal/v1/ai-nodes/heartbeat', [], ['Authorization' => "Bearer {$second['token']}"])->assertOk();
    }

    public function test_revoke_disables_the_credential(): void
    {
        $node = AiNode::factory()->create();
        $admin = $this->fullAdmin();
        $issued = $this->actingAs($admin, 'web')->postJson("/api/v1/ai-nodes/{$node->uuid}/credentials")->json('data');
        $credentialUuid = $issued['uuid'];

        $res = $this->actingAs($admin, 'web')->postJson("/api/v1/ai-nodes/{$node->uuid}/credentials/{$credentialUuid}/revoke");
        $res->assertOk()->assertJsonPath('data.status', 'REVOKED');

        $this->postJson('/internal/v1/ai-nodes/heartbeat', [], ['Authorization' => "Bearer {$issued['token']}"])->assertStatus(401);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_NODE_CREDENTIAL_REVOKED', 'entity_id' => $node->uuid]);
    }

    public function test_cannot_revoke_a_credential_belonging_to_a_different_node(): void
    {
        $nodeA = AiNode::factory()->create();
        $nodeB = AiNode::factory()->create();
        $admin = $this->fullAdmin();

        $issued = $this->actingAs($admin, 'web')->postJson("/api/v1/ai-nodes/{$nodeA->uuid}/credentials")->json('data');

        $this->actingAs($admin, 'web')
            ->postJson("/api/v1/ai-nodes/{$nodeB->uuid}/credentials/{$issued['uuid']}/revoke")
            ->assertStatus(422);

        $this->assertSame(AiNodeCredentialStatus::ACTIVE, AiNodeCredential::where('uuid', $issued['uuid'])->value('status'));
    }

    public function test_revoking_already_revoked_credential_is_idempotent(): void
    {
        $node = AiNode::factory()->create();
        $admin = $this->fullAdmin();
        $issued = $this->actingAs($admin, 'web')->postJson("/api/v1/ai-nodes/{$node->uuid}/credentials")->json('data');

        $this->actingAs($admin, 'web')->postJson("/api/v1/ai-nodes/{$node->uuid}/credentials/{$issued['uuid']}/revoke")->assertOk();
        $before = AuditLog::where('action', 'AI_NODE_CREDENTIAL_REVOKED')->count();

        $this->actingAs($admin, 'web')->postJson("/api/v1/ai-nodes/{$node->uuid}/credentials/{$issued['uuid']}/revoke")->assertOk();
        // No duplicate audit entry for a no-op second revoke.
        $this->assertSame($before, AuditLog::where('action', 'AI_NODE_CREDENTIAL_REVOKED')->count());
    }
}
