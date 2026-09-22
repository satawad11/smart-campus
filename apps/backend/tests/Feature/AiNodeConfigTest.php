<?php

namespace Tests\Feature;

use App\Models\AiNode;
use App\Models\Camera;
use App\Services\Camera\AiNodeCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * CAM-015 — internal (AI-node-facing) GET /internal/v1/ai-nodes/{uuid}/config
 * camera configuration sync, including decrypted RTSP credentials.
 */
class AiNodeConfigTest extends TestCase
{
    use RefreshDatabase;

    private function bearerFor(AiNode $node): array
    {
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_requires_ai_node_token(): void
    {
        $node = AiNode::factory()->create();

        $this->getJson("/internal/v1/ai-nodes/{$node->uuid}/config")->assertStatus(401);
    }

    public function test_returns_decrypted_rtsp_credentials_for_assigned_cameras(): void
    {
        $node = AiNode::factory()->create();
        Camera::factory()->create([
            'ai_node_id' => $node->id,
            'rtsp_url_encrypted' => Crypt::encryptString('rtsp://10.0.0.5:554/stream1'),
            'username_encrypted' => Crypt::encryptString('camuser'),
            'password_encrypted' => Crypt::encryptString('camsecret'),
        ]);

        $res = $this->withHeaders($this->bearerFor($node))->getJson("/internal/v1/ai-nodes/{$node->uuid}/config");

        $res->assertOk();
        $res->assertJsonPath('data.ai_node_uuid', $node->uuid);
        $res->assertJsonCount(1, 'data.cameras');
        $res->assertJsonPath('data.cameras.0.rtsp_url', 'rtsp://10.0.0.5:554/stream1');
        $res->assertJsonPath('data.cameras.0.username', 'camuser');
        $res->assertJsonPath('data.cameras.0.password', 'camsecret');
    }

    public function test_only_includes_cameras_assigned_to_this_node(): void
    {
        $node = AiNode::factory()->create();
        $otherNode = AiNode::factory()->create();
        Camera::factory()->create(['ai_node_id' => $node->id]);
        Camera::factory()->create(['ai_node_id' => $otherNode->id]);

        $res = $this->withHeaders($this->bearerFor($node))->getJson("/internal/v1/ai-nodes/{$node->uuid}/config");

        $res->assertOk();
        $res->assertJsonCount(1, 'data.cameras');
    }

    public function test_camera_with_no_credentials_returns_null_fields(): void
    {
        $node = AiNode::factory()->create();
        Camera::factory()->create([
            'ai_node_id' => $node->id,
            'rtsp_url_encrypted' => null,
            'username_encrypted' => null,
            'password_encrypted' => null,
        ]);

        $res = $this->withHeaders($this->bearerFor($node))->getJson("/internal/v1/ai-nodes/{$node->uuid}/config");

        $res->assertOk();
        $res->assertJsonPath('data.cameras.0.rtsp_url', null);
        $res->assertJsonPath('data.cameras.0.username', null);
        $res->assertJsonPath('data.cameras.0.password', null);
    }

    public function test_a_node_cannot_fetch_another_nodes_configuration(): void
    {
        $node = AiNode::factory()->create();
        $otherNode = AiNode::factory()->create();

        $res = $this->withHeaders($this->bearerFor($node))->getJson("/internal/v1/ai-nodes/{$otherNode->uuid}/config");

        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'AI_NODE_CONFIG_FORBIDDEN');
    }

    public function test_toggle_flags_are_included(): void
    {
        $node = AiNode::factory()->create();
        Camera::factory()->create([
            'ai_node_id' => $node->id,
            'face_detection_enabled' => true,
            'face_recognition_enabled' => false,
            'liveness_enabled' => true,
            'snapshot_enabled' => false,
        ]);

        $res = $this->withHeaders($this->bearerFor($node))->getJson("/internal/v1/ai-nodes/{$node->uuid}/config");

        $res->assertOk();
        $res->assertJsonPath('data.cameras.0.face_detection_enabled', true);
        $res->assertJsonPath('data.cameras.0.face_recognition_enabled', false);
        $res->assertJsonPath('data.cameras.0.liveness_enabled', true);
        $res->assertJsonPath('data.cameras.0.snapshot_enabled', false);
    }
}
