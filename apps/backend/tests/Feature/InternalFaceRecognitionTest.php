<?php

namespace Tests\Feature;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\FaceProfileStatus;
use App\Models\AIModel;
use App\Models\AiNode;
use App\Models\AuditLog;
use App\Models\FaceProfile;
use App\Models\Person;
use App\Services\Camera\AiNodeCredentialService;
use App\Services\Face\FaceEmbeddingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CAM-009 — internal (AI-node-facing) POST /internal/v1/face/recognize.
 *
 * Composes the existing FaceEmbeddingRepository (search) and
 * FaceRecognitionClassificationService (FACE-013 classify) behind the
 * ai_node.token machine-auth boundary (CAM-004) — no new AI algorithm, no
 * access decision, no raw vector ever returned.
 */
class InternalFaceRecognitionTest extends TestCase
{
    use RefreshDatabase;

    protected AIModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'face.recognition.match_threshold' => 0.80,
            'face.recognition.review_threshold' => 0.60,
            'face.recognition.ambiguity_margin' => 0.05,
        ]);

        $this->model = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_EMBEDDING,
            'code' => 'arcface-r50-v1',
            'version' => '1.0.0',
            'embedding_dimension' => 512,
            'distance_metric' => 'cosine',
            'status' => AIModelStatus::ACTIVE,
        ]);
    }

    /** Deterministic normalized unit vector. */
    private function unitVector(int $dimension = 512, int $seed = 1): array
    {
        mt_srand($seed);
        $vec = [];
        $sumSq = 0.0;
        for ($i = 0; $i < $dimension; $i++) {
            $val = mt_rand(-1000, 1000) / 1000.0;
            $vec[] = $val;
            $sumSq += $val * $val;
        }
        $norm = sqrt($sumSq);

        return array_map(fn ($x) => $x / $norm, $vec);
    }

    private function bearerFor(AiNode $node): array
    {
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function enrolledProfileVector(int $seed): array
    {
        $vector = $this->unitVector(512, $seed);
        $profile = FaceProfile::factory()->create([
            'person_id' => Person::factory()->create()->id,
            'status' => FaceProfileStatus::ACTIVE,
        ]);
        app(FaceEmbeddingRepository::class)->store($profile, $vector, $this->model);

        return $vector;
    }

    public function test_requires_ai_node_token(): void
    {
        $this->postJson('/internal/v1/face/recognize', [
            'embedding' => $this->unitVector(),
            'model_code' => 'arcface-r50-v1',
            'model_version' => '1.0.0',
        ])->assertStatus(401);
    }

    public function test_match_classification_for_a_near_identical_vector(): void
    {
        $node = AiNode::factory()->create();
        $vector = $this->enrolledProfileVector(seed: 7);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/face/recognize', [
            'embedding' => $vector, // identical vector -> similarity ~1.0
            'model_code' => 'arcface-r50-v1',
            'model_version' => '1.0.0',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.classification', 'MATCH');
        $this->assertArrayHasKey('candidate_person_uuid', $res->json('data.matched_candidate'));
    }

    public function test_unknown_classification_when_no_enrolled_profiles(): void
    {
        $node = AiNode::factory()->create();

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/face/recognize', [
            'embedding' => $this->unitVector(seed: 99),
            'model_code' => 'arcface-r50-v1',
            'model_version' => '1.0.0',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.classification', 'UNKNOWN');
    }

    public function test_response_never_contains_raw_vector_or_internal_ids(): void
    {
        $node = AiNode::factory()->create();
        $vector = $this->enrolledProfileVector(seed: 3);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/face/recognize', [
            'embedding' => $vector,
            'model_code' => 'arcface-r50-v1',
            'model_version' => '1.0.0',
        ]);

        $body = $res->getContent();
        $this->assertStringNotContainsString((string) $vector[0], $body);
        $this->assertArrayNotHasKey('id', $res->json('data'));
        $this->assertArrayNotHasKey('embedding', $res->json('data'));
        foreach (['access_decision', 'allow', 'deny', 'door_state'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $res->json('data'));
        }
    }

    public function test_unregistered_model_code_version_rejected(): void
    {
        $node = AiNode::factory()->create();

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/face/recognize', [
            'embedding' => $this->unitVector(),
            'model_code' => 'nonexistent-model',
            'model_version' => '9.9.9',
        ]);

        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'EMBEDDING_SEARCH_FAILED');
    }

    public function test_does_not_write_a_security_audit_row(): void
    {
        $node = AiNode::factory()->create();
        $headers = $this->bearerFor($node); // issuing the credential itself is audited (CAM-004)
        $before = AuditLog::count();

        $this->withHeaders($headers)->postJson('/internal/v1/face/recognize', [
            'embedding' => $this->unitVector(),
            'model_code' => 'arcface-r50-v1',
            'model_version' => '1.0.0',
        ])->assertOk();

        // Routine camera-processing traffic, not a discrete administrative
        // event — same reasoning as AI node heartbeats (CAM-003).
        $this->assertSame($before, AuditLog::count());
    }

    public function test_rejects_prohibited_and_malformed_fields(): void
    {
        $node = AiNode::factory()->create();
        $headers = $this->bearerFor($node);

        $this->withHeaders($headers)->postJson('/internal/v1/face/recognize', [
            'embedding' => $this->unitVector(),
            'model_code' => 'arcface-r50-v1',
            'model_version' => '1.0.0',
            'access_decision' => 'ALLOW',
        ])->assertStatus(422);

        $this->withHeaders($headers)->postJson('/internal/v1/face/recognize', [
            'embedding' => 'not-an-array',
            'model_code' => 'arcface-r50-v1',
            'model_version' => '1.0.0',
        ])->assertStatus(422);
    }
}
