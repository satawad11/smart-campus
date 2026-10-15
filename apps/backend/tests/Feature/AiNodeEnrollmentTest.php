<?php

namespace Tests\Feature;

use App\Enums\AiNodeCredentialStatus;
use App\Enums\DeviceStatus;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AiNode;
use App\Models\AiNodeCredential;
use App\Models\AiNodeEnrollmentCode;
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
 * COM-03e: one-time enrollment codes. An administrator issues a code; the
 * node exchanges it once for its UUID and a machine credential.
 */
class AiNodeEnrollmentTest extends TestCase
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
        $role = Role::create(['code' => 'ENR_'.uniqid(), 'name' => 'Enrollment Admin', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function issueCode(AiNode $node): string
    {
        $admin = $this->userWith(['ai_node.view', 'ai_node.manage']);
        $res = $this->actingAs($admin, 'web')->postJson("/api/v1/ai-nodes/{$node->uuid}/enrollment-codes");
        $res->assertStatus(201);

        return $res->json('data.code');
    }

    public function test_issuing_requires_manage_permission_and_returns_the_code_once(): void
    {
        $node = AiNode::factory()->create();
        $this->postJson("/api/v1/ai-nodes/{$node->uuid}/enrollment-codes")->assertStatus(401);
        $viewer = $this->userWith(['ai_node.view']);
        $this->actingAs($viewer, 'web')->postJson("/api/v1/ai-nodes/{$node->uuid}/enrollment-codes")->assertStatus(403);

        $code = $this->issueCode($node);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $code);

        $row = AiNodeEnrollmentCode::where('ai_node_id', $node->id)->firstOrFail();
        $this->assertSame(hash('sha256', str_replace('-', '', $code)), $row->code_hash);
        $this->assertArrayNotHasKey('code_hash', $row->toArray());
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_NODE_ENROLLMENT_CODE_ISSUED', 'entity_id' => $node->uuid]);
        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString(str_replace('-', '', $code), json_encode($log->getAttributes()));
        }
    }

    public function test_node_exchanges_a_code_once_for_a_working_credential(): void
    {
        $node = AiNode::factory()->create();
        $old = AiNodeCredential::factory()->create(['ai_node_id' => $node->id]);
        $code = $this->issueCode($node);
        auth()->guard('web')->logout();

        // Lower case, spaces and an O typed for 0 are accepted
        $typed = strtolower(str_replace('-', ' ', $code));
        $typed = str_replace('0', 'o', $typed);
        $res = $this->postJson('/internal/v1/ai-nodes/enroll', ['code' => $typed]);
        $res->assertStatus(201)
            ->assertJsonPath('data.ai_node_uuid', $node->uuid)
            ->assertJsonPath('data.node_code', $node->node_code);
        $token = $res->json('data.token');

        // The new token authenticates the node; the earlier credential is rotated out
        $this->withToken($token)->postJson('/internal/v1/ai-nodes/heartbeat', ['cpu_usage' => 10.0, 'ram_usage' => 10.0])
            ->assertOk();
        $this->assertSame(AiNodeCredentialStatus::REVOKED, $old->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_NODE_ENROLLED', 'entity_id' => $node->uuid]);

        // Second use fails
        $this->postJson('/internal/v1/ai-nodes/enroll', ['code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_ENROLLMENT_CODE');
    }

    public function test_expired_replaced_unknown_or_inactive_node_codes_fail_the_same_way(): void
    {
        $node = AiNode::factory()->create();
        $first = $this->issueCode($node);
        $second = $this->issueCode($node); // invalidates $first

        foreach ([$first, 'ZZZZ-ZZZZ-ZZZZ', '', str_repeat('A', 200)] as $bad) {
            $this->postJson('/internal/v1/ai-nodes/enroll', ['code' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'INVALID_ENROLLMENT_CODE');
        }

        $this->travel(31)->minutes();
        $this->postJson('/internal/v1/ai-nodes/enroll', ['code' => $second])->assertStatus(422);
        $this->travelBack();

        $other = AiNode::factory()->create();
        $third = $this->issueCode($other);
        $other->forceFill(['status' => DeviceStatus::INACTIVE])->save();
        $this->postJson('/internal/v1/ai-nodes/enroll', ['code' => $third])->assertStatus(422);
        $this->assertSame(0, AiNodeCredential::whereIn('ai_node_id', [$node->id, $other->id])->count());
    }

    public function test_exchange_is_throttled_per_ip(): void
    {
        config(['ai_node.enrollment.attempts_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/internal/v1/ai-nodes/enroll', ['code' => 'AAAA-AAAA-AAAA'])->assertStatus(422);
        }
        $this->postJson('/internal/v1/ai-nodes/enroll', ['code' => 'AAAA-AAAA-AAAA'])->assertStatus(429);
    }
}
