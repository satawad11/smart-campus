<?php

namespace Tests\Feature;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\AuditAction;
use App\Enums\FaceProfileStatus;
use App\Models\AIModel;
use App\Models\AuditLog;
use App\Models\FaceProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ACR-AI-01: the YuNet + SFace model is registered as DRAFT and becomes the only active
 * embedding model through face:model-activate.
 */
class FaceModelActivateCommandTest extends TestCase
{
    use RefreshDatabase;

    private function sface(): AIModel
    {
        return AIModel::query()->where('code', 'yunet-sface')->where('version', '2021dec')->firstOrFail();
    }

    public function test_migration_registers_the_model_as_draft_512_cosine(): void
    {
        $model = $this->sface();

        $this->assertSame(AIModelType::FACE_EMBEDDING, $model->model_type);
        $this->assertSame(AIModelStatus::DRAFT, $model->status);
        $this->assertFalse((bool) $model->is_default);
        $this->assertSame(512, $model->embedding_dimension);
        $this->assertSame('COSINE', $model->distance_metric);
        $this->assertSame(128, $model->config['native_dimension']);
        $this->assertNull($model->threshold_high, 'thresholds stay in config/face.php (FACE-021)');
    }

    public function test_activation_makes_it_the_only_active_embedding_model_and_audits(): void
    {
        $mock = AIModel::factory()->create([
            'code' => 'arcface-r50-v1', 'version' => '1.0.0',
            'model_type' => AIModelType::FACE_EMBEDDING, 'status' => AIModelStatus::ACTIVE, 'is_default' => true,
        ]);
        $detector = AIModel::factory()->create(['model_type' => AIModelType::FACE_DETECTION]);

        $this->artisan('face:model-activate', ['code' => 'yunet-sface', 'model_version' => '2021dec'])
            ->assertSuccessful();

        $model = $this->sface();
        $this->assertSame(AIModelStatus::ACTIVE, $model->status);
        $this->assertTrue((bool) $model->is_default);
        $this->assertNotNull($model->deployed_at);
        $this->assertSame(AIModelStatus::INACTIVE, $mock->fresh()->status);
        $this->assertFalse((bool) $mock->fresh()->is_default);
        $this->assertSame(AIModelStatus::ACTIVE, $detector->fresh()->status, 'only embedding models change');

        $log = AuditLog::query()->where('action', AuditAction::FACE_MODEL_ACTIVATED->value)->sole();
        $this->assertSame($model->uuid, $log->entity_id);
        $this->assertSame(['arcface-r50-v1@1.0.0'], $log->metadata['deactivated_models']);
    }

    public function test_refuses_to_strand_faces_enrolled_with_another_model_unless_forced(): void
    {
        $mock = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_EMBEDDING, 'status' => AIModelStatus::ACTIVE,
        ]);
        FaceProfile::factory()->count(2)->create([
            'recognition_model_id' => $mock->id, 'status' => FaceProfileStatus::ACTIVE,
        ]);

        $this->artisan('face:model-activate', ['code' => 'yunet-sface', 'model_version' => '2021dec'])
            ->expectsOutputToContain('2 active face profile(s)')
            ->assertFailed();
        $this->assertSame(AIModelStatus::DRAFT, $this->sface()->status);
        $this->assertSame(AIModelStatus::ACTIVE, $mock->fresh()->status);

        $this->artisan('face:model-activate', ['code' => 'yunet-sface', 'model_version' => '2021dec', '--force' => true])
            ->expectsOutputToContain('must be re-enrolled')
            ->assertSuccessful();
        $this->assertSame(AIModelStatus::ACTIVE, $this->sface()->status);
        $this->assertSame(AIModelStatus::INACTIVE, $mock->fresh()->status);
    }

    public function test_unknown_model_fails_and_rerun_is_a_no_op(): void
    {
        $this->artisan('face:model-activate', ['code' => 'nope', 'model_version' => '1'])->assertFailed();

        $this->artisan('face:model-activate', ['code' => 'yunet-sface', 'model_version' => '2021dec'])->assertSuccessful();
        $this->artisan('face:model-activate', ['code' => 'yunet-sface', 'model_version' => '2021dec'])
            ->expectsOutputToContain('already the only active')
            ->assertSuccessful();
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::FACE_MODEL_ACTIVATED->value)->count());
    }
}
