<?php

namespace Tests\Feature;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\FaceRecognitionClassification;
use App\Enums\UserStatus;
use App\Exceptions\Face\FaceEnrollmentException;
use App\Models\AIModel;
use App\Models\AuditLog;
use App\Models\FaceProfile;
use App\Models\FaceSample;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Face\DTO\FacePipelineResult;
use App\Services\Face\DTO\FaceRecognitionTestCandidateDTO;
use App\Services\Face\FacePipelineClient;
use App\Services\Face\FaceRecognitionClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Module 03 Consolidated Security & Acceptance Test Suite (FACE-020).
 *
 * Verifies all security invariants, boundaries, domain isolation,
 * permission guards, and lifecycle transitions for Module 03.
 */
class Module03AcceptanceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected AIModel $model;

    protected User $superAdmin;

    protected User $unauthorizedUser;

    protected Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('face_samples');

        // Create Permissions
        $permissions = [
            'face.view' => 'View Face Profiles',
            'face.enroll' => 'Enroll and Manage Biometrics',
            'face.revoke' => 'Revoke Face Biometrics',
            'face.reenroll' => 'Re-enroll Face Biometrics',
            'face.test' => 'Run Recognition Diagnostics',
            'face.model.view' => 'View AI Models',
            'face.model.manage' => 'Manage AI Models',
        ];

        $permModels = [];
        foreach ($permissions as $code => $name) {
            $permModels[] = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'module' => 'face_biometrics']
            );
        }

        $this->adminRole = Role::factory()->create([
            'code' => 'biometric_super_admin',
            'name' => 'Biometric Super Admin',
        ]);
        $this->adminRole->permissions()->sync(array_column($permModels, 'id'));

        $this->superAdmin = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->superAdmin->roles()->attach($this->adminRole->id);

        $this->unauthorizedUser = User::factory()->create(['status' => UserStatus::ACTIVE]);

        $this->model = AIModel::create([
            'code' => 'arcface-r50-v1',
            'name' => 'ArcFace ResNet-50 v1',
            'version' => '1.0.0',
            'model_type' => AIModelType::FACE_EMBEDDING,
            'framework' => 'ONNX',
            'status' => AIModelStatus::ACTIVE,
            'dimension' => 512,
            'threshold_high' => 0.80,
            'threshold_low' => 0.60,
            'is_default' => true,
        ]);
    }

    protected function fakeImage(string $filename = 'face.jpg'): UploadedFile
    {
        $dummyContent = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00".str_repeat("\x00", 100);

        return UploadedFile::fake()->createWithContent($filename, $dummyContent);
    }

    protected function mockPipeline(float $quality = 0.95): void
    {
        $mockClient = Mockery::mock(FacePipelineClient::class);
        $mockClient->shouldReceive('processEnrollment')
            ->andReturn(new FacePipelineResult(
                faceCount: 1,
                modelCode: 'arcface-r50-v1',
                modelVersion: '1.0.0',
                modelType: 'FACE_EMBEDDING',
                embeddingDimension: 512,
                embedding: array_fill(0, 512, 0.044194),
                qualityScore: $quality,
                blurScore: 0.98,
                brightnessScore: 0.95,
                faceSizeScore: 0.92,
                poseScore: 0.96,
                yaw: 0.5,
                pitch: -0.2,
                roll: 0.1,
                qualityAccepted: true,
                rejectionReasons: [],
                boundingBox: [100, 100, 200, 200]
            ));

        $this->app->instance(FacePipelineClient::class, $mockClient);
    }

    public function test_invariant_biometric_identity_belongs_strictly_to_person(): void
    {
        $this->mockPipeline(0.96);

        $person = Person::factory()->create();
        $student = StudentProfile::factory()->create(['person_id' => $person->id]);

        $response = $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $this->fakeImage(),
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'person_uuid' => $person->uuid,
                    'status' => 'ACTIVE',
                ],
            ]);

        // Face profile is linked strictly to person_id
        $profile = FaceProfile::where('person_id', $person->id)->first();
        $this->assertNotNull($profile);
        $this->assertEquals($person->id, $profile->person_id);

        // Face profile table schema does NOT contain student_profile_id or user_id columns
        $this->assertFalse(\Schema::hasColumn('face_profiles', 'student_profile_id'));
        $this->assertFalse(\Schema::hasColumn('face_profiles', 'user_id'));
    }

    public function test_invariant_zero_raw_embedding_or_storage_path_leakage(): void
    {
        $this->mockPipeline(0.95);
        $person = Person::factory()->create();

        // 1. Enrollment API response
        $enrollResponse = $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $this->fakeImage(),
            ]);

        $enrollResponse->assertStatus(201);
        $enrollJson = $enrollResponse->json();
        $this->assertArrayNotHasKey('embedding', $enrollJson['data']);
        $this->assertArrayNotHasKey('image_path', $enrollJson['data']);
        $this->assertArrayNotHasKey('id', $enrollJson['data']);

        // 2. Profile Show API response
        $profile = FaceProfile::where('person_id', $person->id)->first();
        $showResponse = $this->actingAs($this->superAdmin)
            ->getJson("/api/v1/faces/{$profile->uuid}");

        $showResponse->assertStatus(200);
        $showJson = $showResponse->json();
        $this->assertArrayNotHasKey('embedding', $showJson['data']);
        $this->assertArrayNotHasKey('image_path', $showJson['data']);
        $this->assertArrayNotHasKey('id', $showJson['data']);

        // 3. Audit Logs never contain raw vectors or private filesystem paths
        $auditLogs = AuditLog::where('entity_id', $person->uuid)->get();
        foreach ($auditLogs as $log) {
            $rawLogStr = json_encode($log->toArray());
            $this->assertStringNotContainsString('0.044194', $rawLogStr);
            $this->assertStringNotContainsString('biometrics/faces/samples', $rawLogStr);
        }
    }

    public function test_invariant_separation_of_perception_vs_access_policy(): void
    {
        $classificationService = app(FaceRecognitionClassificationService::class);

        $topCandidate = new FaceRecognitionTestCandidateDTO(
            candidateFaceProfileUuid: '00000000-0000-0000-0000-000000000001',
            candidatePersonUuid: '00000000-0000-0000-0000-000000000002',
            rank: 1,
            distance: 0.12,
            similarity: 0.88,
            model: ['code' => 'arcface-r50-v1', 'version' => '1.0.0', 'type' => 'FACE_EMBEDDING'],
            status: 'ACTIVE'
        );

        $secondCandidate = new FaceRecognitionTestCandidateDTO(
            candidateFaceProfileUuid: '00000000-0000-0000-0000-000000000003',
            candidatePersonUuid: '00000000-0000-0000-0000-000000000004',
            rank: 2,
            distance: 0.50,
            similarity: 0.50,
            model: ['code' => 'arcface-r50-v1', 'version' => '1.0.0', 'type' => 'FACE_EMBEDDING'],
            status: 'ACTIVE'
        );

        $result = $classificationService->classify(
            candidates: [$topCandidate, $secondCandidate],
            model: $this->model
        );

        $this->assertEquals(FaceRecognitionClassification::MATCH, $result->classification);

        // Verification: Classification result contains strictly biometric perception outcomes
        $resultArr = $result->toArray();
        $this->assertArrayNotHasKey('access_decision', $resultArr);
        $this->assertArrayNotHasKey('door_state', $resultArr);
        $this->assertArrayNotHasKey('relay_triggered', $resultArr);
    }

    /**
     * FACE-021 boundary: recognition MUST fail closed when the explicit policy
     * threshold configuration is absent — the generic AIModel threshold_high/low
     * calibration hints are never silently promoted to production policy. This
     * is deliberately unconfigured (config([...] => null)) regardless of the
     * human-approved pilot defaults phpunit.xml sets for every other test.
     */
    public function test_recognition_fails_closed_when_thresholds_unconfigured(): void
    {
        config([
            'face.recognition.match_threshold' => null,
            'face.recognition.review_threshold' => null,
        ]);

        $classificationService = app(FaceRecognitionClassificationService::class);

        $candidate = new FaceRecognitionTestCandidateDTO(
            candidateFaceProfileUuid: '00000000-0000-0000-0000-000000000001',
            candidatePersonUuid: '00000000-0000-0000-0000-000000000002',
            rank: 1,
            distance: 0.12,
            similarity: 0.88,
            model: ['code' => 'arcface-r50-v1', 'version' => '1.0.0', 'type' => 'FACE_EMBEDDING'],
            status: 'ACTIVE'
        );

        // Ensure the model's own metadata carries no override either — a
        // clean "nothing configured anywhere" fail-closed scenario.
        $this->model->metadata = null;
        $this->model->save();

        try {
            $classificationService->classify(candidates: [$candidate], model: $this->model->fresh());
            $this->fail('Expected classification to fail closed with RECOGNITION_POLICY_NOT_CONFIGURED.');
        } catch (FaceEnrollmentException $e) {
            $this->assertSame('RECOGNITION_POLICY_NOT_CONFIGURED', $e->errorCode);
            $this->assertSame(422, $e->statusCode);
        }
    }

    public function test_end_to_end_biometric_lifecycle(): void
    {
        $this->mockPipeline(0.95);
        $person = Person::factory()->create();

        // 1. Initial Enrollment -> ACTIVE
        $enrollRes = $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $this->fakeImage('enroll1.jpg'),
            ]);
        $enrollRes->assertStatus(201);
        $profileUuid = $enrollRes->json('data.face_profile_uuid');

        // 2. Revocation -> REVOKED
        $revokeRes = $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/faces/{$profileUuid}/revoke", [
                'reason' => 'Graduated and withdrew consent',
            ]);
        $revokeRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'REVOKED',
                    'revocation_reason' => 'Graduated and withdrew consent',
                ],
            ]);

        // 3. Re-enrollment -> ACTIVE with preserved sample history
        $this->mockPipeline(0.98);
        $reenrollRes = $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/faces/{$profileUuid}/reenroll", [
                'image' => $this->fakeImage('reenroll.jpg'),
                'reason' => 'Enrolled in post-graduate program',
            ]);
        $reenrollRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'ACTIVE',
                    'revocation_reason' => null,
                    'samples_count' => 2,
                ],
            ]);

        $this->assertEquals(2, FaceSample::where('face_profile_id', FaceProfile::where('uuid', $profileUuid)->value('id'))->count());
    }

    public function test_security_prohibited_parameters_are_unconditionally_rejected(): void
    {
        $person = Person::factory()->create();

        $response = $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/persons/{$person->uuid}/face/enroll", [
                'image' => $this->fakeImage(),
                'person_id' => 9999,
                'embedding' => array_fill(0, 512, 0.1),
                'access_decision' => 'ALLOW',
                'status' => 'ACTIVE',
            ]);

        $response->assertStatus(422);
    }
}
