<?php

namespace Tests\Feature;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\AuditAction;
use App\Enums\FaceProfileStatus;
use App\Enums\UserStatus;
use App\Models\AIModel;
use App\Models\AuditLog;
use App\Models\FaceDuplicateReview;
use App\Models\FaceProfile;
use App\Models\FaceSample;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature Tests for Restricted Face Recognition Diagnostic Testing (FACE-012).
 */
class FaceRecognitionTestApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;

    protected User $unauthorizedUser;

    protected AIModel $embeddingModel;

    protected AIModel $secondaryEmbeddingModel;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permission & role for face testing
        $testPerm = Permission::firstOrCreate(
            ['code' => 'face.test'],
            ['name' => 'Test Face Recognition', 'module' => 'face_biometrics']
        );

        $testRole = Role::factory()->create([
            'code' => 'face_diagnostic_tester',
            'name' => 'Face Diagnostic Tester',
        ]);
        $testRole->permissions()->syncWithoutDetaching([$testPerm->id]);

        $this->operator = User::factory()->create([
            'status' => UserStatus::ACTIVE,
        ]);
        $this->operator->roles()->attach($testRole);

        $this->unauthorizedUser = User::factory()->create([
            'status' => UserStatus::ACTIVE,
        ]);

        // Register default canonical active FACE_EMBEDDING model
        $this->embeddingModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_EMBEDDING,
            'code' => 'arcface-r50-v1',
            'version' => '1.0.0',
            'embedding_dimension' => 512,
            'distance_metric' => 'cosine',
            'status' => AIModelStatus::ACTIVE,
        ]);

        // Register secondary distinct FACE_EMBEDDING model
        $this->secondaryEmbeddingModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_EMBEDDING,
            'code' => 'facenet-512-v2',
            'version' => '2.0.0',
            'embedding_dimension' => 512,
            'distance_metric' => 'cosine',
            'status' => AIModelStatus::ACTIVE,
        ]);
    }

    /** Helper to generate a normalized 512-d unit vector */
    protected function generateVector(float $val = 0.04419417, int $dim = 512): array
    {
        $vec = array_fill(0, $dim, 0.0);
        $vec[0] = $val;

        return $vec;
    }

    /** Helper to create fake image without GD dependency */
    protected function fakeImage(string $filename = 'test_face.jpg', string $mime = 'image/jpeg'): UploadedFile
    {
        $dummyContent = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00".str_repeat("\x00", 200);

        return UploadedFile::fake()->createWithContent($filename, $dummyContent);
    }

    /** Helper to create an enrolled profile with specific embedding */
    protected function createEnrolledProfile(array $vector, ?AIModel $model = null, FaceProfileStatus $status = FaceProfileStatus::ACTIVE): FaceProfile
    {
        $model = $model ?? $this->embeddingModel;
        $person = Person::factory()->create();

        $profile = FaceProfile::factory()->create([
            'person_id' => $person->id,
            'status' => $status,
            'recognition_model_id' => $model->id,
            'embedding_dimension' => count($vector),
            'quality_score' => 0.95,
            'enrolled_by_user_id' => $this->operator->id,
            'enrolled_at' => now(),
        ]);

        $formatted = '['.implode(',', $vector).']';
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('UPDATE face_profiles SET embedding = ?::vector WHERE id = ?', [$formatted, $profile->id]);
        } else {
            DB::statement('UPDATE face_profiles SET embedding = ? WHERE id = ?', [$formatted, $profile->id]);
        }

        return $profile->fresh(['person', 'recognitionModel']);
    }

    /** Helper to mock a successful perception pipeline response */
    protected function mockAiServiceSuccess(?array $vector = null, string $code = 'arcface-r50-v1', string $version = '1.0.0'): void
    {
        $vector = $vector ?? $this->generateVector(1.0);

        Http::fake([
            '*/internal/v1/face/enroll' => Http::response([
                'success' => true,
                'face_count' => 1,
                'model_code' => $code,
                'model_version' => $version,
                'model_type' => 'FACE_EMBEDDING',
                'embedding_dimension' => count($vector),
                'embedding' => $vector,
                'quality' => [
                    'quality_score' => 0.95,
                    'blur_score' => 0.98,
                    'brightness_score' => 0.92,
                    'face_size_score' => 0.90,
                    'pose_score' => 0.96,
                    'accepted' => true,
                    'rejection_reasons' => [],
                ],
                'bounding_box' => [
                    'x_min' => 100.0,
                    'y_min' => 100.0,
                    'x_max' => 300.0,
                    'y_max' => 300.0,
                ],
            ], 200),
        ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/v1/faces/recognition-test', [
            'image' => $this->fakeImage(),
        ]);

        $response->assertStatus(401);
    }

    public function test_missing_permission_face_test_returns_403(): void
    {
        $response = $this->actingAs($this->unauthorizedUser)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ]);

        $response->assertStatus(403);
    }

    public function test_authorized_operator_with_face_test_permission_succeeds(): void
    {
        $queryVec = $this->generateVector(1.0);
        $this->mockAiServiceSuccess($queryVec);

        $enrolledProfile = $this->createEnrolledProfile($queryVec);

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Face recognition diagnostic test completed successfully.',
                'data' => [
                    'model' => [
                        'code' => 'arcface-r50-v1',
                        'version' => '1.0.0',
                        'type' => 'FACE_EMBEDDING',
                    ],
                    'face_count' => 1,
                    'quality_score' => 0.95,
                    'top_k' => 5,
                    'candidate_count' => 1,
                    'candidates' => [
                        [
                            'candidate_face_profile_uuid' => $enrolledProfile->uuid,
                            'candidate_person_uuid' => $enrolledProfile->person->uuid,
                            'rank' => 1,
                            'status' => 'ACTIVE',
                        ],
                    ],
                ],
            ]);
    }

    public function test_zero_faces_detected_returns_422(): void
    {
        Http::fake([
            '*/internal/v1/face/enroll' => Http::response([
                'detail' => [
                    'error_code' => 'NO_FACE',
                    'message' => 'No face detected in the provided image.',
                ],
            ], 422),
        ]);

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'NO_FACE',
                ],
            ]);
    }

    public function test_multiple_faces_detected_returns_422(): void
    {
        Http::fake([
            '*/internal/v1/face/enroll' => Http::response([
                'detail' => [
                    'error_code' => 'MULTIPLE_FACES',
                    'face_count' => 3,
                    'message' => 'Multiple faces (3) detected in image.',
                ],
            ], 422),
        ]);

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'MULTIPLE_FACES',
                    'details' => [
                        'face_count' => 3,
                    ],
                ],
            ]);
    }

    public function test_low_quality_face_rejected_returns_422(): void
    {
        Http::fake([
            '*/internal/v1/face/enroll' => Http::response([
                'detail' => [
                    'error_code' => 'FACE_QUALITY_REJECTED',
                    'message' => 'Face sample does not meet quality requirements.',
                    'rejection_reasons' => ['BLURRY_IMAGE', 'LOW_LIGHTING'],
                    'quality' => [
                        'quality_score' => 0.45,
                        'blur_score' => 0.30,
                    ],
                ],
            ], 422),
        ]);

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'FACE_QUALITY_REJECTED',
                    'details' => [
                        'rejection_reasons' => ['BLURRY_IMAGE', 'LOW_LIGHTING'],
                    ],
                ],
            ]);
    }

    public function test_invalid_image_or_unsupported_mime_rejected(): void
    {
        $textFile = UploadedFile::fake()->create('test.txt', 10, 'text/plain');

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $textFile,
            ]);

        $response->assertStatus(422);
    }

    public function test_oversized_image_rejected(): void
    {
        // 12MB file exceeds 10MB limit
        $oversizedFile = UploadedFile::fake()->create('large.jpg', 12000, 'image/jpeg');

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $oversizedFile,
            ]);

        $response->assertStatus(422);
    }

    public function test_forbidden_client_injected_parameters_rejected(): void
    {
        $this->mockAiServiceSuccess();

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
                'embedding' => [0.1, 0.2, 0.3],
                'person_id' => 9999,
                'threshold' => 0.85,
                'decision' => 'MATCH',
            ]);

        $response->assertStatus(422);
    }

    public function test_exact_model_and_version_candidate_filtering(): void
    {
        $queryVec = $this->generateVector(1.0);
        $this->mockAiServiceSuccess($queryVec, 'arcface-r50-v1', '1.0.0');

        // Enrolled profile under primary model (arcface-r50-v1)
        $matchingProfile = $this->createEnrolledProfile($queryVec, $this->embeddingModel);

        // Enrolled profile under secondary model (facenet-512-v2)
        $differentModelProfile = $this->createEnrolledProfile($queryVec, $this->secondaryEmbeddingModel);

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ]);

        $response->assertStatus(200);
        $candidates = $response->json('data.candidates');

        $this->assertCount(1, $candidates);
        $this->assertEquals($matchingProfile->uuid, $candidates[0]['candidate_face_profile_uuid']);
    }

    public function test_candidate_ranking_by_cosine_distance(): void
    {
        $queryVec = $this->generateVector(1.0);
        $this->mockAiServiceSuccess($queryVec);

        // Profile 1: exact match -> distance 0.0 (sim 1.0)
        $profile1 = $this->createEnrolledProfile($queryVec);

        // Profile 2: close match (0.9 on dim 0, 0.43588989 on dim 1 -> sim ~0.9)
        $closeVec = array_fill(0, 512, 0.0);
        $closeVec[0] = 0.9;
        $closeVec[1] = 0.43588989;
        $profile2 = $this->createEnrolledProfile($closeVec);

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
                'top_k' => 5,
            ]);

        $response->assertStatus(200);
        $candidates = $response->json('data.candidates');

        $this->assertCount(2, $candidates);
        $this->assertEquals($profile1->uuid, $candidates[0]['candidate_face_profile_uuid']);
        $this->assertEquals(1, $candidates[0]['rank']);
        $this->assertEquals($profile2->uuid, $candidates[1]['candidate_face_profile_uuid']);
        $this->assertEquals(2, $candidates[1]['rank']);
        $this->assertGreaterThanOrEqual($candidates[1]['similarity'], $candidates[0]['similarity']);
    }

    public function test_top_k_bounds_enforced(): void
    {
        $queryVec = $this->generateVector(1.0);
        $this->mockAiServiceSuccess($queryVec);

        $this->createEnrolledProfile($queryVec);
        $this->createEnrolledProfile($queryVec);
        $this->createEnrolledProfile($queryVec);

        // Request top_k = 2
        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
                'top_k' => 2,
            ]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.candidates'));
        $this->assertEquals(2, $response->json('data.top_k'));
    }

    public function test_empty_candidates_returns_safe_empty_list(): void
    {
        $queryVec = $this->generateVector(1.0);
        $this->mockAiServiceSuccess($queryVec);

        // No enrolled profiles exist
        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'candidate_count' => 0,
                    'candidates' => [],
                ],
            ]);
    }

    public function test_response_privacy_never_exposes_raw_vectors_storage_paths_or_internal_ids(): void
    {
        $queryVec = $this->generateVector(1.0);
        $this->mockAiServiceSuccess($queryVec);
        $this->createEnrolledProfile($queryVec);

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ]);

        $response->assertStatus(200);
        $jsonString = $response->getContent();

        // Must not expose raw numeric IDs, raw embeddings, or storage paths
        $this->assertStringNotContainsString('"candidate_face_profile_id"', $jsonString);
        $this->assertStringNotContainsString('"candidate_person_id"', $jsonString);
        $this->assertStringNotContainsString('"embedding"', $jsonString);
        $this->assertStringNotContainsString('"vector"', $jsonString);
        $this->assertStringNotContainsString('"storage_path"', $jsonString);
        $this->assertStringNotContainsString('"file_path"', $jsonString);
    }

    public function test_zero_persistent_face_profiles_samples_or_embeddings_created(): void
    {
        $queryVec = $this->generateVector(1.0);
        $this->mockAiServiceSuccess($queryVec);

        $initialProfilesCount = FaceProfile::count();
        $initialSamplesCount = FaceSample::count();

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ]);

        $response->assertStatus(200);

        $this->assertEquals($initialProfilesCount, FaceProfile::count());
        $this->assertEquals($initialSamplesCount, FaceSample::count());
    }

    public function test_person_student_user_remain_unmutated(): void
    {
        $person = Person::factory()->create();
        $student = StudentProfile::factory()->create(['person_id' => $person->id]);
        $user = User::factory()->create(['person_id' => $person->id]);

        $queryVec = $this->generateVector(1.0);
        $this->mockAiServiceSuccess($queryVec);

        $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ])->assertStatus(200);

        $this->assertEquals($person->uuid, $person->fresh()->uuid);
        $this->assertEquals($student->uuid, $student->fresh()->uuid);
        $this->assertEquals($user->uuid, $user->fresh()->uuid);
    }

    public function test_no_duplicate_review_records_created(): void
    {
        $queryVec = $this->generateVector(1.0);
        $this->mockAiServiceSuccess($queryVec);
        $this->createEnrolledProfile($queryVec);

        $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ])->assertStatus(200);

        $this->assertEquals(0, FaceDuplicateReview::count());
    }

    public function test_no_match_review_unknown_classification_or_access_decision_emitted(): void
    {
        $queryVec = $this->generateVector(1.0);
        $this->mockAiServiceSuccess($queryVec);
        $this->createEnrolledProfile($queryVec);

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ]);

        $response->assertStatus(200);
        $jsonString = $response->getContent();

        // Invariant: FACE-012 returns candidate evidence only. Zero MATCH/REVIEW/UNKNOWN, zero ALLOW/DENY
        $this->assertStringNotContainsString('"decision"', $jsonString);
        $this->assertStringNotContainsString('"classification"', $jsonString);
        $this->assertStringNotContainsString('"MATCH"', $jsonString);
        $this->assertStringNotContainsString('"UNKNOWN"', $jsonString);
        $this->assertStringNotContainsString('"ALLOW"', $jsonString);
        $this->assertStringNotContainsString('"DENY"', $jsonString);
    }

    public function test_audit_log_recorded_without_raw_biometric_data(): void
    {
        $queryVec = $this->generateVector(1.0);
        $this->mockAiServiceSuccess($queryVec);
        $this->createEnrolledProfile($queryVec);

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::FACE_RECOGNITION_TESTED->value,
            'user_id' => $this->operator->id,
            'module' => 'face_biometrics',
            'entity_type' => 'FaceRecognitionTest',
        ]);

        /** @var AuditLog $log */
        $log = AuditLog::where('action', AuditAction::FACE_RECOGNITION_TESTED->value)->latest('id')->firstOrFail();

        $metadataString = json_encode($log->metadata);
        $this->assertStringNotContainsString('embedding', $metadataString);
        $this->assertStringNotContainsString('vector', $metadataString);
        $this->assertStringNotContainsString('image_bytes', $metadataString);
        $this->assertStringNotContainsString('storage_path', $metadataString);
    }

    public function test_non_embedding_model_is_rejected(): void
    {
        // Deactivate all FACE_EMBEDDING models
        AIModel::where('model_type', AIModelType::FACE_EMBEDDING)->update(['status' => AIModelStatus::INACTIVE]);

        // Register only FACE_DETECTION model
        AIModel::factory()->create([
            'model_type' => AIModelType::FACE_DETECTION,
            'code' => 'yolov8-face-v1',
            'version' => '1.0.0',
            'status' => AIModelStatus::ACTIVE,
        ]);

        $this->mockAiServiceSuccess();

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'INCOMPATIBLE_MODEL',
                ],
            ]);
    }

    public function test_ai_service_unavailable_returns_503(): void
    {
        Http::fake([
            '*/internal/v1/face/enroll' => Http::response(null, 500),
        ]);

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/faces/recognition-test', [
                'image' => $this->fakeImage(),
            ]);

        $response->assertStatus(503)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'AI_SERVICE_UNAVAILABLE',
                ],
            ]);
    }
}
