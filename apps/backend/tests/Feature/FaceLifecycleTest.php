<?php

namespace Tests\Feature;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\AuditAction;
use App\Enums\FaceProfileStatus;
use App\Enums\FaceSamplePose;
use App\Enums\FaceSampleSource;
use App\Models\AIModel;
use App\Models\FaceProfile;
use App\Models\FaceSample;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Face\DTO\FacePipelineResult;
use App\Services\Face\FacePipelineClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class FaceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $revokerUser;

    protected User $reenrollerUser;

    protected User $unauthorizedUser;

    protected AIModel $activeEmbeddingModel;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('face_samples');

        // Create Permissions
        $viewPerm = Permission::firstOrCreate(
            ['code' => 'face.view'],
            ['name' => 'View Face Profiles', 'module' => 'face_biometrics']
        );
        $revokePerm = Permission::firstOrCreate(
            ['code' => 'face.revoke'],
            ['name' => 'Revoke Face Biometrics', 'module' => 'face_biometrics']
        );
        $reenrollPerm = Permission::firstOrCreate(
            ['code' => 'face.reenroll'],
            ['name' => 'Re-enroll Face Biometrics', 'module' => 'face_biometrics']
        );

        $revokerRole = Role::factory()->create(['code' => 'face_revoker', 'name' => 'Face Revoker']);
        $revokerRole->permissions()->attach([$viewPerm->id, $revokePerm->id]);

        $reenrollerRole = Role::factory()->create(['code' => 'face_reenroller', 'name' => 'Face Reenroller']);
        $reenrollerRole->permissions()->attach([$viewPerm->id, $reenrollPerm->id]);

        $adminRole = Role::factory()->create(['code' => 'face_admin', 'name' => 'Face Admin']);
        $adminRole->permissions()->attach([$viewPerm->id, $revokePerm->id, $reenrollPerm->id]);

        $this->adminUser = User::factory()->create(['status' => 'ACTIVE']);
        $this->adminUser->roles()->attach($adminRole->id);

        $this->revokerUser = User::factory()->create(['status' => 'ACTIVE']);
        $this->revokerUser->roles()->attach($revokerRole->id);

        $this->reenrollerUser = User::factory()->create(['status' => 'ACTIVE']);
        $this->reenrollerUser->roles()->attach($reenrollerRole->id);

        $this->unauthorizedUser = User::factory()->create(['status' => 'ACTIVE']);

        // Create Active AI Model
        $this->activeEmbeddingModel = AIModel::create([
            'code' => 'facenet512',
            'name' => 'FaceNet-512 Standard',
            'version' => '1.0.0',
            'model_type' => AIModelType::FACE_EMBEDDING,
            'framework' => 'ONNX',
            'status' => AIModelStatus::ACTIVE,
            'dimension' => 512,
            'threshold_high' => 0.75,
            'threshold_low' => 0.50,
            'is_default' => true,
        ]);
    }

    protected function mockPerceptionPipeline(float $qualityScore = 0.95): void
    {
        $mockClient = Mockery::mock(FacePipelineClient::class);
        $mockClient->shouldReceive('processEnrollment')
            ->andReturn(new FacePipelineResult(
                faceCount: 1,
                modelCode: 'facenet512',
                modelVersion: '1.0.0',
                modelType: 'FACE_EMBEDDING',
                embeddingDimension: 512,
                embedding: array_fill(0, 512, 0.04419),
                qualityScore: $qualityScore,
                blurScore: 0.96,
                brightnessScore: 0.94,
                faceSizeScore: 0.95,
                poseScore: 0.95,
                yaw: 1.2,
                pitch: -0.8,
                roll: 0.3,
                qualityAccepted: true,
                rejectionReasons: [],
                boundingBox: [100, 100, 200, 200]
            ));

        $this->app->instance(FacePipelineClient::class, $mockClient);
    }

    protected function fakeImage(string $filename = 'face.jpg'): UploadedFile
    {
        $dummyContent = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00".str_repeat("\x00", 200);

        return UploadedFile::fake()->createWithContent($filename, $dummyContent);
    }

    protected function createPersonWithActiveFace(): array
    {
        $person = Person::factory()->create();

        $faceProfile = FaceProfile::create([
            'person_id' => $person->id,
            'status' => FaceProfileStatus::ACTIVE,
            'recognition_model_id' => $this->activeEmbeddingModel->id,
            'embedding_dimension' => 512,
            'quality_score' => 0.92,
            'enrolled_at' => now()->subDays(5),
            'enrolled_by_user_id' => $this->adminUser->id,
        ]);

        $sample = FaceSample::create([
            'face_profile_id' => $faceProfile->id,
            'image_path' => 'biometrics/faces/samples/original-sample.jpg',
            'pose' => FaceSamplePose::FRONT,
            'source' => FaceSampleSource::UPLOAD,
            'quality_score' => 0.92,
            'blur_score' => 0.91,
            'brightness_score' => 0.90,
            'face_size_score' => 0.93,
            'yaw' => 0.0,
            'pitch' => 0.0,
            'roll' => 0.0,
            'accepted' => true,
            'checksum' => hash('sha256', 'original_image_bytes'),
            'mime_type' => 'image/jpeg',
            'captured_at' => now()->subDays(5),
        ]);

        return [$person, $faceProfile, $sample];
    }

    public function test_revoke_face_profile_by_profile_endpoint_succeeds(): void
    {
        [$person, $faceProfile, $sample] = $this->createPersonWithActiveFace();

        $response = $this->actingAs($this->revokerUser)
            ->postJson("/api/v1/faces/{$faceProfile->uuid}/revoke", [
                'reason' => 'Student graduated from university',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'face_profile_uuid' => $faceProfile->uuid,
                    'person_uuid' => $person->uuid,
                    'status' => 'REVOKED',
                    'revocation_reason' => 'Student graduated from university',
                ],
            ]);

        $this->assertDatabaseHas('face_profiles', [
            'id' => $faceProfile->id,
            'status' => FaceProfileStatus::REVOKED->value,
            'revocation_reason' => 'Student graduated from university',
        ]);

        $this->assertNotNull(FaceProfile::find($faceProfile->id)->revoked_at);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::FACE_REVOKED->value,
            'entity_type' => 'Person',
            'entity_id' => $person->uuid,
            'user_id' => $this->revokerUser->id,
        ]);
    }

    public function test_revoke_by_person_endpoint_succeeds(): void
    {
        [$person, $faceProfile, $sample] = $this->createPersonWithActiveFace();

        $response = $this->actingAs($this->revokerUser)
            ->postJson("/api/v1/persons/{$person->uuid}/face/revoke", [
                'reason' => 'Biometric consent withdrawn by person',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'face_profile_uuid' => $faceProfile->uuid,
                    'person_uuid' => $person->uuid,
                    'status' => 'REVOKED',
                    'revocation_reason' => 'Biometric consent withdrawn by person',
                ],
            ]);
    }

    public function test_cannot_revoke_already_revoked_profile(): void
    {
        [$person, $faceProfile, $sample] = $this->createPersonWithActiveFace();
        $faceProfile->update([
            'status' => FaceProfileStatus::REVOKED,
            'revoked_at' => now(),
            'revocation_reason' => 'Previously revoked',
        ]);

        $response = $this->actingAs($this->revokerUser)
            ->postJson("/api/v1/faces/{$faceProfile->uuid}/revoke", [
                'reason' => 'Trying to revoke again',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'ALREADY_REVOKED',
                ],
            ]);
    }

    public function test_reenroll_updates_profile_and_preserves_sample_history(): void
    {
        $this->mockPerceptionPipeline(0.96);

        [$person, $faceProfile, $originalSample] = $this->createPersonWithActiveFace();
        $faceProfile->update([
            'status' => FaceProfileStatus::REVOKED,
            'revoked_at' => now()->subDay(),
            'revocation_reason' => 'Old photo expired',
        ]);

        $imageFile = $this->fakeImage('new_face.jpg');

        $response = $this->actingAs($this->reenrollerUser)
            ->postJson("/api/v1/faces/{$faceProfile->uuid}/reenroll", [
                'image' => $imageFile,
                'reason' => 'Updated biometric photo for new academic term',
                'pose' => 'FRONT',
                'source' => 'UPLOAD',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'face_profile_uuid' => $faceProfile->uuid,
                    'person_uuid' => $person->uuid,
                    'status' => 'ACTIVE',
                    'revocation_reason' => null,
                    'revoked_at' => null,
                    'samples_count' => 2,
                ],
            ]);

        // Verify database: status is ACTIVE, revocation fields cleared
        $updatedProfile = FaceProfile::find($faceProfile->id);
        $this->assertEquals(FaceProfileStatus::ACTIVE, $updatedProfile->status);
        $this->assertNull($updatedProfile->revoked_at);
        $this->assertNull($updatedProfile->revocation_reason);

        // Verify sample history preserved: 2 samples exist
        $this->assertEquals(2, FaceSample::where('face_profile_id', $faceProfile->id)->count());

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::FACE_REENROLLED->value,
            'entity_type' => 'Person',
            'entity_id' => $person->uuid,
            'user_id' => $this->reenrollerUser->id,
        ]);
    }

    public function test_reenroll_by_person_endpoint_succeeds(): void
    {
        $this->mockPerceptionPipeline(0.97);

        [$person, $faceProfile, $originalSample] = $this->createPersonWithActiveFace();
        $imageFile = $this->fakeImage('reenroll_person.jpg');

        $response = $this->actingAs($this->reenrollerUser)
            ->postJson("/api/v1/persons/{$person->uuid}/face/reenroll", [
                'image' => $imageFile,
                'reason' => 'Replaced blurry baseline image',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'person_uuid' => $person->uuid,
                    'status' => 'ACTIVE',
                ],
            ]);
    }

    public function test_lifecycle_endpoints_enforce_permissions(): void
    {
        [$person, $faceProfile, $sample] = $this->createPersonWithActiveFace();

        // Unauthorized user cannot revoke
        $response = $this->actingAs($this->unauthorizedUser)
            ->postJson("/api/v1/faces/{$faceProfile->uuid}/revoke", [
                'reason' => 'Unauthorized revoke',
            ]);
        $response->assertStatus(403);

        // Revoker user cannot re-enroll
        $response = $this->actingAs($this->revokerUser)
            ->postJson("/api/v1/faces/{$faceProfile->uuid}/reenroll", [
                'image' => $this->fakeImage('test.jpg'),
                'reason' => 'Attempt re-enroll',
            ]);
        $response->assertStatus(403);

        // Reenroller user cannot revoke
        $response = $this->actingAs($this->reenrollerUser)
            ->postJson("/api/v1/faces/{$faceProfile->uuid}/revoke", [
                'reason' => 'Attempt revoke',
            ]);
        $response->assertStatus(403);
    }

    public function test_forbidden_parameters_are_rejected(): void
    {
        [$person, $faceProfile, $sample] = $this->createPersonWithActiveFace();

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/faces/{$faceProfile->uuid}/revoke", [
                'reason' => 'Test reason',
                'status' => 'ACTIVE',
                'embedding' => [0.1, 0.2],
            ]);

        $response->assertStatus(422);
    }

    public function test_list_and_show_face_profiles(): void
    {
        [$person, $faceProfile, $sample] = $this->createPersonWithActiveFace();

        // List profiles
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/faces');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'face_profile_uuid',
                        'person_uuid',
                        'status',
                        'quality_score',
                        'enrolled_at',
                    ],
                ],
            ]);

        // Show single profile
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/faces/{$faceProfile->uuid}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'face_profile_uuid' => $faceProfile->uuid,
                    'person_uuid' => $person->uuid,
                    'status' => 'ACTIVE',
                ],
            ]);

        // Person faces
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/persons/{$person->uuid}/faces");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'face_profile_uuid' => $faceProfile->uuid,
                    'person_uuid' => $person->uuid,
                ],
            ]);
    }
}
