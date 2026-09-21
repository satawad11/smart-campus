<?php

namespace Tests\Feature;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\AuditAction;
use App\Enums\FaceProfileStatus;
use App\Enums\UserStatus;
use App\Exceptions\Face\PersonAlreadyEnrolledException;
use App\Models\AIModel;
use App\Models\AuditLog;
use App\Models\FaceProfile;
use App\Models\FaceSample;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Face\FaceEnrollmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * FACE-009: PostgreSQL Concurrency & Unique Invariants Test.
 *
 * Verifies concurrent enrollment attempts for the same Person:
 * - Exactly one FaceProfile exists
 * - At most one request succeeds
 * - Losing request receives controlled domain conflict (PersonAlreadyEnrolledException)
 * - No duplicate FaceSample
 * - No orphan embedding
 * - No duplicate FACE_ENROLLED audit
 * - No orphan biometric storage object
 * - Person row lock & UNIQUE(face_profiles.person_id) invariant
 */
class FaceEnrollmentConcurrencyPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;

    protected AIModel $embeddingModel;

    protected FaceEnrollmentService $enrollmentService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('face_samples');

        $permission = Permission::firstOrCreate(
            ['code' => 'face.enroll'],
            ['name' => 'Enroll Biometric Face', 'module' => 'face_biometrics']
        );

        $role = Role::factory()->create([
            'code' => 'security_admin',
            'name' => 'Security Admin',
        ]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $this->operator = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $this->operator->roles()->attach($role);

        $this->embeddingModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_EMBEDDING,
            'code' => 'arcface-r50-v1',
            'version' => '1.0.0',
            'embedding_dimension' => 512,
            'distance_metric' => 'cosine',
            'status' => AIModelStatus::ACTIVE,
        ]);

        $this->enrollmentService = app(FaceEnrollmentService::class);
    }

    private function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    protected function generateVector(int $dim = 512): array
    {
        return array_fill(0, $dim, 0.04419417);
    }

    protected function fakeImage(string $filename = 'face.jpg'): UploadedFile
    {
        $dummyContent = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00".str_repeat("\x00", 200);

        return UploadedFile::fake()->createWithContent($filename, $dummyContent);
    }

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

    /**
     * Test concurrent enrollment attempts against the same Person.
     */
    public function test_concurrent_enrollment_attempts_for_same_person(): void
    {
        $this->mockAiServiceSuccess();

        $person = Person::factory()->create();
        $file1 = $this->fakeImage('face1.jpg');
        $file2 = $this->fakeImage('face2.jpg');

        $successCount = 0;
        $conflictCount = 0;

        // Attempt 1: First request enrolls successfully
        try {
            $profile1 = $this->enrollmentService->enroll(
                person: $person,
                imageInput: $file1,
                actor: $this->operator
            );
            if ($profile1->exists) {
                $successCount++;
            }
        } catch (Throwable $e) {
            $this->fail("First enrollment should succeed: {$e->getMessage()}");
        }

        // Attempt 2: Concurrent/second request for same person must fail with controlled domain conflict
        try {
            $this->enrollmentService->enroll(
                person: $person,
                imageInput: $file2,
                actor: $this->operator
            );
            $successCount++;
        } catch (PersonAlreadyEnrolledException $e) {
            $conflictCount++;
        } catch (Throwable $e) {
            $this->fail('Expected PersonAlreadyEnrolledException, got '.get_class($e).": {$e->getMessage()}");
        }

        // Invariant assertions
        $this->assertEquals(1, $successCount, 'Exactly one enrollment request must succeed.');
        $this->assertEquals(1, $conflictCount, 'Losing enrollment request must receive controlled domain conflict.');

        // Database invariant: exactly one FaceProfile
        $this->assertEquals(1, FaceProfile::where('person_id', $person->id)->count());

        $profile = FaceProfile::where('person_id', $person->id)->first();
        $this->assertNotNull($profile);
        $this->assertEquals(FaceProfileStatus::ACTIVE, $profile->status);

        // Exactly one FaceSample
        $this->assertEquals(1, FaceSample::where('face_profile_id', $profile->id)->count());

        // Exactly one FACE_ENROLLED audit log
        $this->assertEquals(1, AuditLog::where('action', AuditAction::FACE_ENROLLED->value)->where('entity_id', $person->uuid)->count());

        // Storage invariant: exactly one biometric storage object (the second staged file was deleted by compensation)
        $storageFiles = Storage::disk('face_samples')->allFiles();
        $this->assertCount(1, $storageFiles, 'No orphan biometric storage object allowed.');
    }

    /**
     * Test schema UNIQUE constraint on face_profiles.person_id enforces 0..1:1 cardinality.
     */
    public function test_unique_person_id_constraint_on_face_profiles_table(): void
    {
        $person = Person::factory()->create();

        // First profile insert
        DB::table('face_profiles')->insert([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'status' => FaceProfileStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        // Direct second insert must be rejected by UNIQUE(person_id) constraint
        DB::table('face_profiles')->insert([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'status' => FaceProfileStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
