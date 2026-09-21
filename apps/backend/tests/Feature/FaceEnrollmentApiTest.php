<?php

namespace Tests\Feature;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\AuditAction;
use App\Enums\FaceProfileStatus;
use App\Enums\UserStatus;
use App\Models\AIModel;
use App\Models\FaceProfile;
use App\Models\FaceSample;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Face\FaceEmbeddingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FaceEnrollmentApiTest extends TestCase
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
            ['name' => 'Enroll Biometric Face', 'module' => 'face_biometrics']
        );

        $role = Role::factory()->create([
            'code' => 'security_admin',
            'name' => 'Security Admin',
            'description' => 'Security administration role',
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
        $vec = array_fill(0, $dim, 0.04419417); // sqrt(1/512) for unit norm

        return $vec;
    }

    /** Helper to create fake image without GD dependency */
    protected function fakeImage(string $filename = 'face.jpg', int $sizeKb = 100, string $mime = 'image/jpeg'): UploadedFile
    {
        $dummyContent = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00".str_repeat("\x00", 200);

        return UploadedFile::fake()->createWithContent($filename, $dummyContent);
    }

    /** Helper to mock a successful perception pipeline response */
    protected function mockAiServiceSuccess(): void
    {
        Http::fake([
            '*/internal/v1/face/enroll' => Http::response([
                'success' => true,
                'face_count' => 1,
                'model_code' => 'arcface-r50-v1',
                'model_version' => '1.0.0',
                'model_type' => 'FACE_EMBEDDING',
                'embedding_dimension' => 512,
                'embedding' => $this->generateVector(512),
                'quality' => [
                    'quality_score' => 0.95,
                    'blur_score' => 0.98,
                    'brightness_score' => 0.92,
                    'face_size_score' => 0.90,
                    'pose_score' => 0.96,
                    'yaw' => 1.0,
                    'pitch' => 0.5,
                    'roll' => -0.2,
                    'accepted' => true,
                    'rejection_reasons' => [],
                ],
                'bounding_box' => [
                    'x1' => 50,
                    'y1' => 50,
                    'x2' => 180,
                    'y2' => 200,
                    'confidence' => 0.99,
                ],
            ], 200),
        ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $person = Person::factory()->create();
        $file = $this->fakeImage('face.jpg');

        $response = $this->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
            'image' => $file,
        ]);

        $response->assertStatus(401);
    }

    public function test_missing_permission_returns_403(): void
    {
        $unprivilegedUser = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $person = Person::factory()->create();
        $file = $this->fakeImage('face.jpg');

        $response = $this->actingAs($unprivilegedUser)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $file,
            ]);

        $response->assertStatus(403);
    }

    public function test_numeric_person_id_is_rejected(): void
    {
        $person = Person::factory()->create();
        $file = $this->fakeImage('face.jpg');

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->id}/face/enroll", [
                'image' => $file,
            ]);

        $response->assertStatus(404);
    }

    public function test_nonexistent_person_uuid_returns_404(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000000';
        $file = $this->fakeImage('face.jpg');

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$fakeUuid}/face/enroll", [
                'image' => $file,
            ]);

        $response->assertStatus(404);
    }

    public function test_successful_enrollment_creates_profile_sample_embedding_and_audit(): void
    {
        $this->mockAiServiceSuccess();

        $person = Person::factory()->create();
        $file = $this->fakeImage('portrait.jpg');

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $file,
                'pose' => 'FRONT',
                'source' => 'UPLOAD',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.person_uuid', $person->uuid);
        $response->assertJsonPath('data.status', 'ACTIVE');
        $response->assertJsonPath('data.model.code', 'arcface-r50-v1');
        $response->assertJsonPath('data.sample.pose', 'FRONT');
        $response->assertJsonPath('data.sample.source', 'UPLOAD');

        // Verify DB persistence
        $this->assertDatabaseHas('face_profiles', [
            'person_id' => $person->id,
            'status' => 'ACTIVE',
            'recognition_model_id' => $this->embeddingModel->id,
            'enrolled_by_user_id' => $this->operator->id,
        ]);

        $profile = FaceProfile::where('person_id', $person->id)->first();
        $this->assertNotNull($profile);
        $this->assertEquals(512, $profile->embedding_dimension);
        $this->assertNotNull($profile->embedding);

        $this->assertDatabaseHas('face_samples', [
            'face_profile_id' => $profile->id,
            'accepted' => true,
            'pose' => 'FRONT',
            'source' => 'UPLOAD',
        ]);

        // Verify image stored in private disk
        $sample = FaceSample::where('face_profile_id', $profile->id)->first();
        $this->assertNotNull($sample);
        Storage::disk('face_samples')->assertExists($sample->image_path);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->operator->id,
            'action' => AuditAction::FACE_ENROLLED->value,
            'module' => 'face_biometrics',
            'entity_type' => 'Person',
            'entity_id' => $person->uuid,
        ]);

        // Response should NOT contain raw embedding or internal IDs
        $json = $response->json();
        $this->assertArrayNotHasKey('id', $json['data']);
        $this->assertArrayNotHasKey('person_id', $json['data']);
        $this->assertArrayNotHasKey('embedding', $json['data']);
        $this->assertArrayNotHasKey('vector', $json['data']);
    }

    public function test_target_is_person_without_student_or_user_required(): void
    {
        $this->mockAiServiceSuccess();

        // Standalone person without user or student profile
        $person = Person::factory()->create();
        $this->assertNull($person->user);
        $this->assertNull($person->studentProfile);

        $file = $this->fakeImage('face.jpg');
        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $file,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('face_profiles', ['person_id' => $person->id]);
    }

    public function test_existing_active_face_profile_returns_409_conflict(): void
    {
        $person = Person::factory()->create();
        FaceProfile::factory()->create([
            'person_id' => $person->id,
            'status' => FaceProfileStatus::ACTIVE,
        ]);

        $file = $this->fakeImage('face.jpg');
        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $file,
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'FACE_ALREADY_ENROLLED');
    }

    public function test_zero_faces_detected_returns_422_and_cleans_up_storage(): void
    {
        Http::fake([
            '*/internal/v1/face/enroll' => Http::response([
                'success' => false,
                'error_code' => 'NO_FACE',
                'message' => 'No face detected in enrollment image.',
                'face_count' => 0,
                'rejection_reasons' => ['NO_FACE'],
            ], 422),
        ]);

        $person = Person::factory()->create();
        $file = $this->fakeImage('empty.jpg');

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'NO_FACE');

        // No database records created
        $this->assertDatabaseMissing('face_profiles', ['person_id' => $person->id]);

        // Staged storage files cleaned up (0 files remaining)
        $files = Storage::disk('face_samples')->allFiles();
        $this->assertEmpty($files);
    }

    public function test_multiple_faces_detected_returns_422(): void
    {
        Http::fake([
            '*/internal/v1/face/enroll' => Http::response([
                'success' => false,
                'error_code' => 'MULTIPLE_FACES',
                'message' => 'Multiple faces (2) detected.',
                'face_count' => 2,
                'rejection_reasons' => ['MULTIPLE_FACES'],
            ], 422),
        ]);

        $person = Person::factory()->create();
        $file = $this->fakeImage('group.jpg');

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'MULTIPLE_FACES');
        $this->assertDatabaseMissing('face_profiles', ['person_id' => $person->id]);
    }

    public function test_quality_rejected_face_returns_422_with_reasons(): void
    {
        Http::fake([
            '*/internal/v1/face/enroll' => Http::response([
                'success' => false,
                'error_code' => 'FACE_QUALITY_REJECTED',
                'message' => 'Face quality criteria not met.',
                'face_count' => 1,
                'rejection_reasons' => ['IMAGE_BLUR', 'IMAGE_TOO_DARK'],
                'quality' => [
                    'quality_score' => 0.35,
                    'blur_score' => 0.20,
                    'brightness_score' => 0.30,
                    'accepted' => false,
                ],
            ], 422),
        ]);

        $person = Person::factory()->create();
        $file = $this->fakeImage('blurry.jpg');

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'FACE_QUALITY_REJECTED');
        $this->assertDatabaseMissing('face_profiles', ['person_id' => $person->id]);
    }

    public function test_forbidden_client_injected_fields_are_rejected(): void
    {
        $person = Person::factory()->create();
        $file = $this->fakeImage('face.jpg');

        $response = $this->actingAs($this->operator)
            ->post("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $file,
                'embedding' => 'forbidden-vector',
                'quality_score' => 0.99,
                'recognition_model_id' => 999,
                'enrolled_by_user_id' => 1,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $fields = $response->json('error.fields');
        $this->assertArrayHasKey('embedding', $fields);
        $this->assertArrayHasKey('quality_score', $fields);
        $this->assertArrayHasKey('recognition_model_id', $fields);
        $this->assertArrayHasKey('enrolled_by_user_id', $fields);
    }

    public function test_person_primary_photo_and_status_remain_unmutated(): void
    {
        $this->mockAiServiceSuccess();

        $person = Person::factory()->create([
            'primary_photo' => 'person-photos/original.jpg',
        ]);

        $file = $this->fakeImage('portrait.jpg');

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $file,
            ]);

        $response->assertStatus(201);

        $person->refresh();
        $this->assertEquals('person-photos/original.jpg', $person->primary_photo);
    }

    public function test_storage_compensation_when_database_transaction_fails(): void
    {
        $this->mockAiServiceSuccess();

        $person = Person::factory()->create();
        $file = $this->fakeImage('face.jpg');

        // Mock FaceEmbeddingRepository to throw exception during atomic DB transaction
        $mockEmbeddingRepo = $this->mock(FaceEmbeddingRepository::class);
        $mockEmbeddingRepo->shouldReceive('store')
            ->once()
            ->andThrow(new \RuntimeException('Database disk failure or pgvector write crash.'));

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $file,
            ]);

        $response->assertStatus(500);

        // Assert database state is clean (no profile, no sample, no embedding, no audit)
        $this->assertDatabaseMissing('face_profiles', ['person_id' => $person->id]);
        $this->assertDatabaseMissing('face_samples', []);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::FACE_ENROLLED->value]);

        // Assert storage compensation deleted the staged object
        $files = Storage::disk('face_samples')->allFiles();
        $this->assertEmpty($files, 'Staged biometric image must be purged when DB transaction fails.');
    }

    public function test_storage_compensation_when_ai_perception_fails(): void
    {
        Http::fake([
            '*/internal/v1/face/enroll' => Http::response([
                'detail' => 'AI Service Internal Error',
            ], 500),
        ]);

        $person = Person::factory()->create();
        $file = $this->fakeImage('face.jpg');

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $file,
            ]);

        $response->assertStatus(503);
        $response->assertJsonPath('error.code', 'AI_SERVICE_UNAVAILABLE');

        // No database records
        $this->assertDatabaseMissing('face_profiles', ['person_id' => $person->id]);

        // Storage compensation purged the staged image
        $files = Storage::disk('face_samples')->allFiles();
        $this->assertEmpty($files, 'Staged file must be deleted when AI pipeline returns 500/503.');
    }

    public function test_canonical_alias_route_requires_uuid_and_rejects_numeric_person_id(): void
    {
        $this->mockAiServiceSuccess();

        $person = Person::factory()->create();
        $file = $this->fakeImage('face.jpg');

        // 1. Alias route succeeds with valid person_uuid
        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/enroll', [
                'person_uuid' => $person->uuid,
                'image' => $file,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.person_uuid', $person->uuid);

        // 2. Reject numeric person_id in request body
        $person2 = Person::factory()->create();
        $file2 = $this->fakeImage('face2.jpg');

        $response2 = $this->actingAs($this->operator)
            ->post('/api/v1/faces/enroll', [
                'person_id' => $person2->id,
                'person_uuid' => $person2->uuid,
                'image' => $file2,
            ], ['Accept' => 'application/json']);

        $response2->assertStatus(422);
        $fields = $response2->json('error.fields');
        $this->assertArrayHasKey('person_id', $fields);
    }

    public function test_model_binding_strictly_requires_face_embedding_model_type(): void
    {
        // Update model to FACE_RECOGNITION (which is invalid for embedding storage)
        $this->embeddingModel->update([
            'model_type' => AIModelType::FACE_RECOGNITION,
            'status' => AIModelStatus::ACTIVE,
        ]);

        $this->mockAiServiceSuccess();

        $person = Person::factory()->create();
        $file = $this->fakeImage('face.jpg');

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $file,
            ]);

        $response->assertStatus(500);

        // Assert no profile saved and storage compensated
        $this->assertDatabaseMissing('face_profiles', ['person_id' => $person->id]);
        $files = Storage::disk('face_samples')->allFiles();
        $this->assertEmpty($files);
    }

    public function test_existing_profile_cannot_be_silently_overwritten_or_re_enrolled(): void
    {
        $person = Person::factory()->create();
        FaceProfile::factory()->create([
            'person_id' => $person->id,
            'status' => FaceProfileStatus::REVOKED,
            'revocation_reason' => 'Previous enrollment revoked.',
        ]);

        $this->mockAiServiceSuccess();
        $file = $this->fakeImage('face.jpg');

        // FACE-009 rejects creating duplicate profile for person (person_id is UNIQUE)
        // FACE-014 remains owner of re-enrollment
        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $file,
            ]);

        // Returns error (500/409) rather than silently overwriting the revoked profile
        $this->assertNotEquals(201, $response->getStatusCode());

        $profile = FaceProfile::where('person_id', $person->id)->first();
        $this->assertEquals(FaceProfileStatus::REVOKED, $profile->status);
    }

    public function test_response_privacy_never_exposes_internal_ids_raw_embedding_or_storage_path(): void
    {
        $this->mockAiServiceSuccess();

        $person = Person::factory()->create();
        $file = $this->fakeImage('portrait.jpg');

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $file,
            ]);

        $response->assertStatus(201);
        $content = $response->getContent();

        // Must not expose raw vector, disk driver, absolute server path, internal numeric IDs
        $this->assertStringNotContainsString('embedding', $content);
        $this->assertStringNotContainsString('vector', $content);
        $this->assertStringNotContainsString('face_samples', $content);
        $this->assertStringNotContainsString('storage/app', $content);
        $this->assertStringNotContainsString('trace', $content);

        $json = $response->json('data');
        $this->assertArrayNotHasKey('id', $json);
        $this->assertArrayNotHasKey('person_id', $json);
        $this->assertArrayNotHasKey('recognition_model_id', $json);
        $this->assertArrayNotHasKey('enrolled_by_user_id', $json);
        $this->assertArrayNotHasKey('image_path', $json['sample']);
    }
}
