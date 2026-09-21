<?php

namespace Tests\Feature;

use App\Enums\AIModelType;
use App\Enums\FaceProfileStatus;
use App\Enums\FaceSamplePose;
use App\Enums\FaceSampleSource;
use App\Models\AIModel;
use App\Models\FaceProfile;
use App\Models\FaceSample;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FACE-002: FaceProfile & FaceSample Schema & Architecture Tests.
 *
 * Verifies table structure, columns, types, integrity constraints,
 * UUID public identity, Person domain boundaries, and immutability invariants.
 */
class FaceProfileSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_face_profiles_and_face_samples_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('face_profiles'));
        $this->assertTrue(Schema::hasTable('face_samples'));

        $expectedProfileColumns = [
            'id',
            'uuid',
            'person_id',
            'recognition_model_id',
            'embedding_dimension',
            'quality_score',
            'status',
            'enrolled_by_user_id',
            'enrolled_at',
            'revoked_at',
            'revocation_reason',
            'created_at',
            'updated_at',
        ];

        foreach ($expectedProfileColumns as $column) {
            $this->assertTrue(
                Schema::hasColumn('face_profiles', $column),
                "Missing expected column [{$column}] in face_profiles table."
            );
        }

        $expectedSampleColumns = [
            'id',
            'uuid',
            'face_profile_id',
            'image_path',
            'pose',
            'source',
            'quality_score',
            'blur_score',
            'brightness_score',
            'face_size_score',
            'yaw',
            'pitch',
            'roll',
            'accepted',
            'rejection_reason',
            'checksum',
            'width',
            'height',
            'mime_type',
            'captured_at',
            'created_at',
            'updated_at',
        ];

        foreach ($expectedSampleColumns as $column) {
            $this->assertTrue(
                Schema::hasColumn('face_samples', $column),
                "Missing expected column [{$column}] in face_samples table."
            );
        }
    }

    public function test_uuid_auto_generates_and_is_unique_for_profiles_and_samples(): void
    {
        $person1 = Person::factory()->create();
        $person2 = Person::factory()->create();

        $profile1 = FaceProfile::factory()->create(['person_id' => $person1->id]);
        $profile2 = FaceProfile::factory()->create(['person_id' => $person2->id]);

        $this->assertNotEmpty($profile1->uuid);
        $this->assertTrue(Str::isUuid($profile1->uuid));
        $this->assertNotEmpty($profile2->uuid);
        $this->assertNotEquals($profile1->uuid, $profile2->uuid);

        $sample1 = FaceSample::factory()->create(['face_profile_id' => $profile1->id]);
        $sample2 = FaceSample::factory()->create(['face_profile_id' => $profile1->id]);

        $this->assertNotEmpty($sample1->uuid);
        $this->assertTrue(Str::isUuid($sample1->uuid));
        $this->assertNotEmpty($sample2->uuid);
        $this->assertNotEquals($sample1->uuid, $sample2->uuid);

        // Reject duplicate FaceProfile UUID
        $this->expectException(QueryException::class);
        DB::table('face_profiles')->insert([
            'uuid' => $profile1->uuid,
            'person_id' => Person::factory()->create()->id,
            'status' => FaceProfileStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_person_to_face_profile_cardinality_and_relationships(): void
    {
        $person = Person::factory()->create([
            'first_name' => 'Alice',
            'last_name' => 'Wonderland',
        ]);

        $model = AIModel::factory()->create([
            'code' => 'ARCFACE_R50',
            'model_type' => AIModelType::FACE_RECOGNITION,
        ]);

        $enroller = User::factory()->create();

        $profile = FaceProfile::factory()->create([
            'person_id' => $person->id,
            'recognition_model_id' => $model->id,
            'enrolled_by_user_id' => $enroller->id,
            'status' => FaceProfileStatus::ACTIVE,
        ]);

        // Biometric identity root is Person
        $this->assertEquals($person->id, $profile->person->id);
        $this->assertEquals($profile->id, $person->faceProfile->id);
        $this->assertEquals($model->id, $profile->recognitionModel->id);
        $this->assertEquals($enroller->id, $profile->enrolledBy->id);

        // Enforce 1-to-1 cardinality (person_id UNIQUE)
        $this->expectException(QueryException::class);
        FaceProfile::factory()->create([
            'person_id' => $person->id,
        ]);
    }

    public function test_face_profile_to_samples_relationship_and_cascade_cleanup(): void
    {
        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create(['person_id' => $person->id]);

        $sampleFront = FaceSample::factory()->create([
            'face_profile_id' => $profile->id,
            'pose' => FaceSamplePose::FRONT,
            'source' => FaceSampleSource::CAMERA,
            'quality_score' => 0.95,
            'accepted' => true,
        ]);

        $sampleLeft = FaceSample::factory()->create([
            'face_profile_id' => $profile->id,
            'pose' => FaceSamplePose::LEFT_SLIGHT,
            'source' => FaceSampleSource::CAMERA,
            'quality_score' => 0.89,
            'accepted' => true,
        ]);

        $this->assertCount(2, $profile->samples);
        $this->assertEquals($profile->id, $sampleFront->faceProfile->id);
        $this->assertEquals($profile->id, $sampleLeft->faceProfile->id);

        // Deleting FaceProfile cascade deletes constituent samples
        $profile->delete();
        $this->assertDatabaseMissing('face_profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('face_samples', ['id' => $sampleFront->id]);
        $this->assertDatabaseMissing('face_samples', ['id' => $sampleLeft->id]);
    }

    public function test_enums_and_casts_for_profile_and_samples(): void
    {
        $person = Person::factory()->create();

        $statuses = [
            FaceProfileStatus::ACTIVE,
            FaceProfileStatus::PENDING,
            FaceProfileStatus::REVOKED,
            FaceProfileStatus::RE_ENROLL_REQUIRED,
        ];

        foreach ($statuses as $status) {
            $p = Person::factory()->create();
            $profile = FaceProfile::factory()->create([
                'person_id' => $p->id,
                'status' => $status,
            ]);
            $this->assertEquals($status, $profile->fresh()->status);
        }

        $profile = FaceProfile::factory()->create(['person_id' => $person->id]);

        $poses = [
            FaceSamplePose::FRONT,
            FaceSamplePose::LEFT_SLIGHT,
            FaceSamplePose::RIGHT_SLIGHT,
            FaceSamplePose::OTHER,
        ];

        foreach ($poses as $pose) {
            $sample = FaceSample::factory()->create([
                'face_profile_id' => $profile->id,
                'pose' => $pose,
            ]);
            $this->assertEquals($pose, $sample->fresh()->pose);
        }

        $sources = [
            FaceSampleSource::UPLOAD,
            FaceSampleSource::CAMERA,
            FaceSampleSource::CAPTURE,
        ];

        foreach ($sources as $source) {
            $sample = FaceSample::factory()->create([
                'face_profile_id' => $profile->id,
                'source' => $source,
            ]);
            $this->assertEquals($source, $sample->fresh()->source);
        }
    }

    public function test_domain_boundaries_and_separation_of_concerns(): void
    {
        $profileColumns = Schema::getColumnListing('face_profiles');
        $sampleColumns = Schema::getColumnListing('face_samples');

        // Invariant: Zero StudentProfile foreign keys
        $this->assertNotContains('student_profile_id', $profileColumns);
        $this->assertNotContains('student_id', $profileColumns);
        $this->assertNotContains('student_profile_id', $sampleColumns);

        // Invariant: Zero access decision fields
        $this->assertNotContains('decision', $profileColumns);
        $this->assertNotContains('access_decision', $profileColumns);
        $this->assertNotContains('door_id', $profileColumns);
        $this->assertNotContains('decision', $sampleColumns);

        // Invariant: Zero raw image BLOB/binary columns (relative object keys only)
        $this->assertNotContains('image_blob', $sampleColumns);
        $this->assertNotContains('raw_image', $sampleColumns);
        $this->assertNotContains('binary_data', $sampleColumns);

        // Invariant: embedding column is on face_profiles (FACE-008), zero vector on face_samples
        $this->assertContains('embedding', $profileColumns);
        $this->assertNotContains('embedding', $sampleColumns);
        $this->assertNotContains('vector', $sampleColumns);

        // Invariant: Zero camera/node bindings in FACE-002
        $this->assertNotContains('camera_id', $sampleColumns);
        $this->assertNotContains('ai_node_id', $sampleColumns);
    }

    public function test_delete_person_is_restricted_when_face_profile_exists(): void
    {
        $person = Person::factory()->create();
        FaceProfile::factory()->create(['person_id' => $person->id]);

        // Trying to force delete Person record when face profile exists throws constraint violation
        $this->expectException(QueryException::class);
        $person->forceDelete();
    }
}
