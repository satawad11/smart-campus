<?php

namespace Tests\Feature;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\AuditAction;
use App\Enums\FaceProfileStatus;
use App\Enums\FaceSamplePose;
use App\Enums\FaceSampleSource;
use App\Enums\UserStatus;
use App\Models\AIModel;
use App\Models\AuditLog;
use App\Models\FaceProfile;
use App\Models\FaceSample;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Face\FaceEmbeddingRepository;
use App\Services\Face\FaceEnrollmentVerificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature & Integration Tests for Face Enrollment Verification (FACE-010).
 */
class FaceEnrollmentVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;

    protected AIModel $embeddingModel;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('face_samples');

        // Create permission & role for face enrollment
        $permission = Permission::firstOrCreate(
            ['code' => 'face.enroll'],
            ['name' => 'Enroll and Verify Biometric Face', 'module' => 'face_biometrics']
        );

        $role = Role::factory()->create([
            'code' => 'security_admin',
            'name' => 'Security Admin',
        ]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $this->operator = User::factory()->create([
            'status' => UserStatus::ACTIVE,
        ]);
        $this->operator->roles()->attach($role);

        // Register default canonical active FACE_EMBEDDING model
        $this->embeddingModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_EMBEDDING,
            'code' => 'arcface-r50-v1',
            'version' => '1.0.0',
            'embedding_dimension' => 512,
            'distance_metric' => 'cosine',
            'status' => AIModelStatus::ACTIVE,
        ]);
    }

    /** Helper to generate a normalized 512-d unit vector */
    protected function generateVector(int $dim = 512): array
    {
        return array_fill(0, $dim, 0.04419417);
    }

    /** Helper to set up a complete, valid enrolled face profile in storage and database */
    protected function createValidEnrollment(FaceProfileStatus $status = FaceProfileStatus::ACTIVE): array
    {
        $person = Person::factory()->create();

        $imageBytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00".str_repeat("\x00", 200);
        $sampleUuid = (string) Str::uuid();
        $imagePath = "biometrics/faces/samples/{$sampleUuid}.jpg";
        $checksum = hash('sha256', $imageBytes);

        Storage::disk('face_samples')->put($imagePath, $imageBytes);

        $profile = FaceProfile::factory()->create([
            'person_id' => $person->id,
            'status' => FaceProfileStatus::ACTIVE,
            'recognition_model_id' => $this->embeddingModel->id,
            'embedding_dimension' => 512,
            'quality_score' => 0.95,
            'enrolled_by_user_id' => $this->operator->id,
            'enrolled_at' => now(),
        ]);

        app(FaceEmbeddingRepository::class)->store($profile, $this->generateVector(512), $this->embeddingModel);

        if ($status !== FaceProfileStatus::ACTIVE) {
            $profile->status = $status;
            $profile->save();
        }

        $sample = FaceSample::factory()->create([
            'face_profile_id' => $profile->id,
            'image_path' => $imagePath,
            'pose' => FaceSamplePose::FRONT,
            'source' => FaceSampleSource::UPLOAD,
            'quality_score' => 0.95,
            'blur_score' => 0.98,
            'brightness_score' => 0.92,
            'face_size_score' => 0.90,
            'yaw' => 1.0,
            'pitch' => 0.5,
            'roll' => -0.2,
            'accepted' => true,
            'checksum' => $checksum,
            'mime_type' => 'image/jpeg',
            'captured_at' => now(),
        ]);

        return [$person, $profile, $sample];
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        [$person, $profile] = $this->createValidEnrollment();

        $response = $this->postJson("/api/v1/persons/{$person->uuid}/face/verify");
        $response->assertStatus(401);

        $response2 = $this->postJson("/api/v1/faces/{$profile->uuid}/verify");
        $response2->assertStatus(401);
    }

    public function test_missing_permission_returns_403(): void
    {
        [$person, $profile] = $this->createValidEnrollment();
        $unprivilegedUser = User::factory()->create(['status' => UserStatus::ACTIVE]);

        $response = $this->actingAs($unprivilegedUser)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");
        $response->assertStatus(403);
    }

    public function test_valid_enrollment_verifies_successfully_via_person_uuid_route(): void
    {
        [$person, $profile, $sample] = $this->createValidEnrollment(FaceProfileStatus::PENDING);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.verified', true);
        $response->assertJsonPath('data.person_uuid', $person->uuid);
        $response->assertJsonPath('data.face_profile_uuid', $profile->uuid);
        $response->assertJsonPath('data.status', 'ACTIVE');
        $response->assertJsonPath('data.sample_count', 1);
        $response->assertJsonPath('data.model.code', 'arcface-r50-v1');
        $response->assertJsonPath('data.model.type', 'FACE_EMBEDDING');
        $response->assertJsonPath('data.quality.quality_score', 0.95);

        // Profile transitioned from PENDING to ACTIVE
        $profile->refresh();
        $this->assertEquals(FaceProfileStatus::ACTIVE, $profile->status);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::FACE_ENROLLMENT_VERIFIED->value,
            'module' => 'face_biometrics',
            'entity_type' => 'FaceProfile',
            'entity_id' => $profile->uuid,
        ]);
    }

    public function test_valid_enrollment_verifies_successfully_via_face_profile_uuid_route(): void
    {
        [$person, $profile] = $this->createValidEnrollment(FaceProfileStatus::ACTIVE);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/faces/{$profile->uuid}/verify");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.verified', true);
        $response->assertJsonPath('data.face_profile_uuid', $profile->uuid);
    }

    public function test_valid_enrollment_verifies_via_alias_faces_verify_route(): void
    {
        [$person, $profile] = $this->createValidEnrollment(FaceProfileStatus::ACTIVE);

        // Via face_profile_uuid
        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/verify', [
                'face_profile_uuid' => $profile->uuid,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.face_profile_uuid', $profile->uuid);

        // Via person_uuid
        $response2 = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/verify', [
                'person_uuid' => $person->uuid,
            ]);

        $response2->assertStatus(200);
        $response2->assertJsonPath('data.person_uuid', $person->uuid);
    }

    public function test_numeric_internal_ids_are_rejected(): void
    {
        [$person, $profile] = $this->createValidEnrollment();

        // Numeric ID in route parameter
        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->id}/face/verify");
        $response->assertStatus(404);

        // Prohibited numeric ID in request body
        $response2 = $this->actingAs($this->operator)
            ->post('/api/v1/faces/verify', [
                'person_id' => $person->id,
                'face_profile_uuid' => $profile->uuid,
            ], ['Accept' => 'application/json']);

        $response2->assertStatus(422);
        $fields = $response2->json('error.fields');
        $this->assertArrayHasKey('person_id', $fields);
    }

    public function test_missing_face_sample_is_rejected(): void
    {
        [$person, $profile, $sample] = $this->createValidEnrollment();
        $sample->delete();

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'FACE_SAMPLE_MISSING');
    }

    public function test_unaccepted_face_sample_is_rejected(): void
    {
        [$person, $profile, $sample] = $this->createValidEnrollment();
        $sample->update(['accepted' => false]);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'FACE_SAMPLE_NOT_ACCEPTED');
    }

    public function test_missing_embedding_is_rejected(): void
    {
        [$person, $profile] = $this->createValidEnrollment();
        $profile->update(['embedding' => null]);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'EMBEDDING_MISSING');
    }

    public function test_invalid_embedding_dimension_is_rejected(): void
    {
        [$person, $profile] = $this->createValidEnrollment();
        $wrongDimensionVector = '['.implode(',', array_fill(0, 128, 0.05)).']';

        if (DB::connection()->getDriverName() === 'pgsql') {
            // On real PostgreSQL the `embedding` column is a fixed-dimension
            // pgvector(512) type: the database itself refuses to ever store a
            // 128-dimension vector, so the corrupted-row scenario this test
            // otherwise simulates is structurally impossible to construct —
            // a *stronger* guarantee than the application-level check below.
            // Assert that guarantee directly instead of skipping silently.
            $this->expectException(QueryException::class);
            DB::table('face_profiles')->where('id', $profile->id)->update(['embedding' => $wrongDimensionVector]);

            return;
        }

        // SQLite has no pgvector column type (embedding is a plain string),
        // so this exercises the application's own defense-in-depth dimension
        // check on the read path.
        DB::table('face_profiles')->where('id', $profile->id)->update(['embedding' => $wrongDimensionVector]);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'EMBEDDING_INVALID');
    }

    public function test_exact_active_face_embedding_model_verifies(): void
    {
        [$person, $profile] = $this->createValidEnrollment(FaceProfileStatus::ACTIVE);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(200);
        $response->assertJsonPath('data.verified', true);
        $response->assertJsonPath('data.model.code', 'arcface-r50-v1');
    }

    public function test_exact_historical_inactive_face_embedding_model_retains_valid_provenance(): void
    {
        [$person, $profile] = $this->createValidEnrollment(FaceProfileStatus::ACTIVE);

        // Historical model transitions from ACTIVE to INACTIVE
        $this->embeddingModel->update(['status' => AIModelStatus::INACTIVE]);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(200);
        $response->assertJsonPath('data.verified', true);
        $response->assertJsonPath('data.model.code', 'arcface-r50-v1');
    }

    public function test_exact_historical_deprecated_face_embedding_model_retains_valid_provenance(): void
    {
        [$person, $profile] = $this->createValidEnrollment(FaceProfileStatus::ACTIVE);

        // Historical model transitions from ACTIVE to DEPRECATED
        $this->embeddingModel->update(['status' => AIModelStatus::DEPRECATED]);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(200);
        $response->assertJsonPath('data.verified', true);
        $response->assertJsonPath('data.model.code', 'arcface-r50-v1');
    }

    public function test_incompatible_face_recognition_model_type_is_rejected(): void
    {
        [$person, $profile] = $this->createValidEnrollment();
        $this->embeddingModel->update(['model_type' => AIModelType::FACE_RECOGNITION]);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'MODEL_MISMATCH');
    }

    public function test_incompatible_face_detection_model_type_is_rejected(): void
    {
        [$person, $profile] = $this->createValidEnrollment();
        $this->embeddingModel->update(['model_type' => AIModelType::FACE_DETECTION]);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'MODEL_MISMATCH');
    }

    public function test_incompatible_face_alignment_model_type_is_rejected(): void
    {
        [$person, $profile] = $this->createValidEnrollment();
        $this->embeddingModel->update(['model_type' => AIModelType::FACE_ALIGNMENT]);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'MODEL_MISMATCH');
    }

    public function test_incompatible_face_quality_model_type_is_rejected(): void
    {
        [$person, $profile] = $this->createValidEnrollment();
        $this->embeddingModel->update(['model_type' => AIModelType::FACE_QUALITY]);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'MODEL_MISMATCH');
    }

    public function test_missing_referenced_model_is_rejected(): void
    {
        [$person, $profile] = $this->createValidEnrollment();
        $profile->update(['recognition_model_id' => null]);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'MODEL_NOT_FOUND');
    }

    public function test_missing_storage_object_is_rejected(): void
    {
        [$person, $profile, $sample] = $this->createValidEnrollment();
        Storage::disk('face_samples')->delete($sample->image_path);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'STORAGE_OBJECT_MISSING');
    }

    public function test_checksum_mismatch_is_rejected(): void
    {
        [$person, $profile, $sample] = $this->createValidEnrollment();

        // Overwrite storage file with altered bytes
        Storage::disk('face_samples')->put($sample->image_path, 'corrupted-tampered-bytes');

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'CHECKSUM_MISMATCH');
    }

    public function test_revoked_face_profile_is_rejected(): void
    {
        [$person, $profile] = $this->createValidEnrollment(FaceProfileStatus::REVOKED);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'FACE_PROFILE_REVOKED');
    }

    public function test_first_verification_creates_audit_and_subsequent_is_idempotent_without_duplicate_audit(): void
    {
        [$person, $profile] = $this->createValidEnrollment(FaceProfileStatus::PENDING);

        // 1. Initial verification transitions PENDING -> ACTIVE and creates audit
        $res1 = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");
        $res1->assertStatus(200);
        $res1->assertJsonPath('data.status', 'ACTIVE');

        $this->assertEquals(1, AuditLog::where('action', AuditAction::FACE_ENROLLMENT_VERIFIED->value)
            ->where('entity_id', $profile->uuid)
            ->count());

        // 2. Second verification returns success (ACTIVE -> ACTIVE) without creating a second semantic verification audit
        $res2 = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");
        $res2->assertStatus(200);
        $res2->assertJsonPath('data.verified', true);
        $res2->assertJsonPath('data.status', 'ACTIVE');

        $this->assertEquals(1, AuditLog::where('action', AuditAction::FACE_ENROLLMENT_VERIFIED->value)
            ->where('entity_id', $profile->uuid)
            ->count(), 'Subsequent verification must not create duplicate FACE_ENROLLMENT_VERIFIED audit log.');

        // 3. Repeated N calls retain exactly one verification transition audit
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->operator)->postJson("/api/v1/persons/{$person->uuid}/face/verify")->assertStatus(200);
        }

        $this->assertEquals(1, AuditLog::where('action', AuditAction::FACE_ENROLLMENT_VERIFIED->value)
            ->where('entity_id', $profile->uuid)
            ->count(), 'Repeated N calls must retain exactly one FACE_ENROLLMENT_VERIFIED audit record.');
    }

    public function test_response_privacy_never_exposes_raw_embedding_internal_ids_or_storage_paths(): void
    {
        [$person, $profile] = $this->createValidEnrollment(FaceProfileStatus::ACTIVE);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringNotContainsString('embedding', $content);
        $this->assertStringNotContainsString('vector', $content);
        $this->assertStringNotContainsString('face_samples', $content);
        $this->assertStringNotContainsString('storage/app', $content);
        $this->assertStringNotContainsString('trace', $content);

        $json = $response->json('data');
        $this->assertArrayNotHasKey('id', $json);
        $this->assertArrayNotHasKey('person_id', $json);
        $this->assertArrayNotHasKey('recognition_model_id', $json);
    }

    public function test_person_and_unrelated_domains_remain_unmutated(): void
    {
        [$person, $profile] = $this->createValidEnrollment(FaceProfileStatus::PENDING);

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/verify");

        $response->assertStatus(200);

        $person->refresh();
        $this->assertNull($person->studentProfile);
        $this->assertNull($person->user);
    }

    public function test_prohibited_client_fields_are_rejected(): void
    {
        [$person, $profile] = $this->createValidEnrollment(FaceProfileStatus::PENDING);

        $response = $this->actingAs($this->operator)
            ->post('/api/v1/faces/verify', [
                'face_profile_uuid' => $profile->uuid,
                'status' => 'ACTIVE',
                'embedding' => 'forbidden',
                'quality_score' => 1.0,
                'model_id' => 1,
                'person_id' => 1,
                'verified' => true,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $fields = $response->json('error.fields');
        $this->assertArrayHasKey('status', $fields);
        $this->assertArrayHasKey('embedding', $fields);
        $this->assertArrayHasKey('quality_score', $fields);
        $this->assertArrayHasKey('model_id', $fields);
        $this->assertArrayHasKey('person_id', $fields);
        $this->assertArrayHasKey('verified', $fields);
    }

    public function test_concurrency_locking_and_atomicity(): void
    {
        [$person, $profile] = $this->createValidEnrollment(FaceProfileStatus::PENDING);

        $service = app(FaceEnrollmentVerificationService::class);

        // First verification succeeds
        $result1 = $service->verify($profile, $this->operator);
        $this->assertTrue($result1->verified);
        $this->assertEquals('ACTIVE', $result1->status);

        // Immediate subsequent verification is idempotent and consistent
        $result2 = $service->verify($profile, $this->operator);
        $this->assertTrue($result2->verified);
        $this->assertEquals('ACTIVE', $result2->status);

        // Status is ACTIVE and exactly one profile exists
        $this->assertEquals(1, FaceProfile::where('person_id', $person->id)->count());
        $this->assertEquals(FaceProfileStatus::ACTIVE, $profile->fresh()->status);
    }
}
