<?php

namespace Tests\Feature;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Models\AIModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FACE-001: AI Model Registry Schema & Architecture Tests.
 *
 * Verifies table structure, columns, types, integrity constraints,
 * UUID public identity, domain boundaries, and immutability invariants.
 */
class AIModelRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_models_table_and_expected_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('ai_models'));

        $expectedColumns = [
            'id',
            'uuid',
            'code',
            'name',
            'model_type',
            'version',
            'provider',
            'artifact_path',
            'model_checksum',
            'embedding_dimension',
            'distance_metric',
            'threshold_high',
            'threshold_low',
            'status',
            'is_default',
            'config',
            'metadata',
            'deployed_at',
            'created_at',
            'updated_at',
        ];

        foreach ($expectedColumns as $column) {
            $this->assertTrue(
                Schema::hasColumn('ai_models', $column),
                "Missing expected column [{$column}] in ai_models table."
            );
        }
    }

    public function test_uuid_auto_generates_and_is_unique(): void
    {
        $model1 = AIModel::factory()->create(['code' => 'SCRFD_500M', 'version' => '1.0.0']);
        $model2 = AIModel::factory()->create(['code' => 'ARCFACE_R50', 'version' => '1.0.0']);

        $this->assertNotEmpty($model1->uuid);
        $this->assertTrue(Str::isUuid($model1->uuid));
        $this->assertNotEmpty($model2->uuid);
        $this->assertNotEquals($model1->uuid, $model2->uuid);

        $this->expectException(QueryException::class);
        DB::table('ai_models')->insert([
            'uuid' => $model1->uuid,
            'code' => 'DUP_UUID',
            'name' => 'Duplicate UUID Model',
            'model_type' => AIModelType::FACE_DETECTION->value,
            'version' => '1.0.0',
            'status' => AIModelStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_code_and_version_uniqueness_is_enforced(): void
    {
        AIModel::factory()->create([
            'code' => 'ARCFACE_R100',
            'version' => '1.0.0',
        ]);

        // Same code with different version is allowed (versioning)
        $v2 = AIModel::factory()->create([
            'code' => 'ARCFACE_R100',
            'version' => '2.0.0',
        ]);
        $this->assertNotNull($v2->id);

        // Same code with same version must be rejected
        $this->expectException(QueryException::class);
        AIModel::factory()->create([
            'code' => 'ARCFACE_R100',
            'version' => '1.0.0',
        ]);
    }

    public function test_model_types_and_statuses_cast_and_persist_correctly(): void
    {
        $types = [
            AIModelType::FACE_DETECTION,
            AIModelType::FACE_ALIGNMENT,
            AIModelType::FACE_QUALITY,
            AIModelType::FACE_RECOGNITION,
            AIModelType::FACE_EMBEDDING,
            AIModelType::LIVENESS,
            AIModelType::OTHER,
        ];

        foreach ($types as $idx => $type) {
            $model = AIModel::factory()->create([
                'code' => 'TEST_TYPE_'.$idx,
                'version' => '1.0.0',
                'model_type' => $type,
                'status' => AIModelStatus::ACTIVE,
            ]);

            $this->assertEquals($type, $model->model_type);
            $this->assertEquals(AIModelStatus::ACTIVE, $model->status);
        }

        $statuses = [
            AIModelStatus::DRAFT,
            AIModelStatus::ACTIVE,
            AIModelStatus::INACTIVE,
            AIModelStatus::DEPRECATED,
        ];

        foreach ($statuses as $idx => $status) {
            $model = AIModel::factory()->create([
                'code' => 'TEST_STATUS_'.$idx,
                'version' => '1.0.0',
                'status' => $status,
            ]);

            $this->assertEquals($status, $model->status);
        }
    }

    public function test_json_config_and_metadata_cast_properly(): void
    {
        $config = [
            'input_size' => [112, 112],
            'mean' => [0.5, 0.5, 0.5],
            'std' => [0.5, 0.5, 0.5],
            'swap_rb' => true,
        ];

        $metadata = [
            'author' => 'InsightFace',
            'paper' => 'ArcFace: Additive Angular Margin Loss for Deep Face Recognition',
            'license' => 'MIT',
        ];

        $model = AIModel::factory()->create([
            'code' => 'ARCFACE_CONFIG_TEST',
            'version' => '1.0.0',
            'config' => $config,
            'metadata' => $metadata,
        ]);

        $fresh = $model->fresh();
        $this->assertIsArray($fresh->config);
        $this->assertEquals([112, 112], $fresh->config['input_size']);
        $this->assertTrue($fresh->config['swap_rb']);

        $this->assertIsArray($fresh->metadata);
        $this->assertEquals('InsightFace', $fresh->metadata['author']);
    }

    public function test_artifact_path_and_checksum_integrity(): void
    {
        $sha256 = hash('sha256', 'mock_model_binary_content');
        $storagePath = 'models/face/arcface_r100_v1.onnx';

        $model = AIModel::factory()->create([
            'code' => 'ARCFACE_INTEGRITY',
            'version' => '1.0.0',
            'artifact_path' => $storagePath,
            'model_checksum' => $sha256,
        ]);

        $this->assertEquals($storagePath, $model->artifact_path);
        $this->assertEquals($sha256, $model->model_checksum);
        $this->assertEquals(64, strlen($model->model_checksum));
    }

    public function test_domain_boundaries_and_separation_of_concerns(): void
    {
        $columns = Schema::getColumnListing('ai_models');

        // Invariant: ZERO foreign keys to person, student, or user
        $this->assertNotContains('person_id', $columns);
        $this->assertNotContains('student_profile_id', $columns);
        $this->assertNotContains('user_id', $columns);

        // Invariant: ZERO access decision fields (AI identifies, Backend decides, Policy controls)
        $this->assertNotContains('decision', $columns);
        $this->assertNotContains('access_decision', $columns);
        $this->assertNotContains('door_id', $columns);
        $this->assertNotContains('relay_state', $columns);

        // Invariant: ZERO raw binary/blob columns (models stored in object storage only)
        $this->assertNotContains('binary_data', $columns);
        $this->assertNotContains('model_blob', $columns);
        $this->assertNotContains('weights_data', $columns);

        // Invariant: ZERO pgvector columns in registry (pgvector is for face_profiles only)
        $this->assertNotContains('embedding', $columns);
        $this->assertNotContains('vector', $columns);

        // Invariant: ZERO recognition result fields
        $this->assertNotContains('matched_person_id', $columns);
        $this->assertNotContains('similarity_score', $columns);
    }
}
