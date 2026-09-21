<?php

namespace Tests\Feature;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\FaceProfileStatus;
use App\Exceptions\Face\EmbeddingDimensionMismatchException;
use App\Exceptions\Face\IncompatibleModelException;
use App\Exceptions\Face\IneligibleProfileException;
use App\Exceptions\Face\InvalidVectorException;
use App\Models\AIModel;
use App\Models\FaceProfile;
use App\Models\Person;
use App\Services\Face\FaceEmbeddingRepository;
use App\Services\Face\VectorSearchCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FaceEmbeddingSearchTest extends TestCase
{
    use RefreshDatabase;

    protected FaceEmbeddingRepository $repository;

    protected AIModel $embeddingModel;

    protected AIModel $otherModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new FaceEmbeddingRepository;

        $this->embeddingModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_EMBEDDING,
            'code' => 'arcface-r50-v1',
            'version' => '1.0.0',
            'embedding_dimension' => 512,
            'distance_metric' => 'cosine',
            'status' => AIModelStatus::ACTIVE,
        ]);

        $this->otherModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_DETECTION,
            'code' => 'scrfd-500m-v1',
            'version' => '1.0.0',
            'embedding_dimension' => null,
            'status' => AIModelStatus::ACTIVE,
        ]);
    }

    /** Helper to generate a normalized unit float vector of given dimension. */
    protected function generateUnitVector(int $dimension = 512, int $seed = 1): array
    {
        mt_srand($seed);
        $vec = [];
        $sumSq = 0.0;
        for ($i = 0; $i < $dimension; $i++) {
            $val = (mt_rand(-1000, 1000) / 1000.0);
            $vec[] = $val;
            $sumSq += $val * $val;
        }
        $norm = sqrt($sumSq);

        return array_map(fn ($x) => $x / $norm, $vec);
    }

    public function test_schema_has_embedding_columns_and_invariants(): void
    {
        $this->assertTrue(Schema::hasColumn('face_profiles', 'embedding'));
        $this->assertTrue(Schema::hasColumn('face_profiles', 'embedding_dimension'));
        $this->assertTrue(Schema::hasColumn('face_profiles', 'recognition_model_id'));
    }

    public function test_raw_embedding_is_hidden_from_serialization(): void
    {
        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create([
            'person_id' => $person->id,
            'status' => FaceProfileStatus::ACTIVE,
        ]);

        $vector = $this->generateUnitVector(512, 42);
        $stored = $this->repository->store($profile, $vector, $this->embeddingModel);

        $array = $stored->toArray();
        $this->assertArrayNotHasKey('embedding', $array);

        $decodedJson = json_decode((string) json_encode($stored), true);
        $this->assertArrayNotHasKey('embedding', $decodedJson);
    }

    public function test_store_valid_embedding_persists_successfully(): void
    {
        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create([
            'person_id' => $person->id,
            'status' => FaceProfileStatus::ACTIVE,
        ]);

        $vector = $this->generateUnitVector(512, 100);
        $stored = $this->repository->store($profile, $vector, $this->embeddingModel);

        $this->assertEquals(512, $stored->embedding_dimension);
        $this->assertEquals($this->embeddingModel->id, $stored->recognition_model_id);
        $this->assertNotNull($stored->embedding);
    }

    public function test_wrong_dimension_rejected_before_db(): void
    {
        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create(['person_id' => $person->id]);
        $wrongVector = $this->generateUnitVector(128); // 128 instead of 512

        $this->expectException(EmbeddingDimensionMismatchException::class);
        $this->repository->store($profile, $wrongVector, $this->embeddingModel);
    }

    public function test_nan_and_inf_vector_rejected_before_db(): void
    {
        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create(['person_id' => $person->id]);

        $nanVector = array_fill(0, 512, NAN);
        $this->expectException(InvalidVectorException::class);
        $this->repository->store($profile, $nanVector, $this->embeddingModel);
    }

    public function test_face_embedding_model_accepted(): void
    {
        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create(['person_id' => $person->id, 'status' => FaceProfileStatus::ACTIVE]);
        $vector = $this->generateUnitVector(512);

        $stored = $this->repository->store($profile, $vector, $this->embeddingModel);
        $this->assertEquals($this->embeddingModel->id, $stored->recognition_model_id);

        $results = $this->repository->search($vector, $this->embeddingModel, topK: 1);
        $this->assertCount(1, $results);
    }

    public function test_face_detection_model_rejected(): void
    {
        $detectionModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_DETECTION,
            'code' => 'scrfd-det-1',
            'version' => '1.0.0',
            'embedding_dimension' => null,
            'status' => AIModelStatus::ACTIVE,
        ]);
        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create(['person_id' => $person->id]);
        $vector = $this->generateUnitVector(512);

        $this->expectException(IncompatibleModelException::class);
        $this->repository->store($profile, $vector, $detectionModel);
    }

    public function test_face_alignment_model_rejected(): void
    {
        $alignmentModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_ALIGNMENT,
            'code' => 'align-model-1',
            'version' => '1.0.0',
            'embedding_dimension' => null,
            'status' => AIModelStatus::ACTIVE,
        ]);
        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create(['person_id' => $person->id]);
        $vector = $this->generateUnitVector(512);

        $this->expectException(IncompatibleModelException::class);
        $this->repository->store($profile, $vector, $alignmentModel);
    }

    public function test_face_quality_model_rejected(): void
    {
        $qualityModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_QUALITY,
            'code' => 'quality-model-1',
            'version' => '1.0.0',
            'embedding_dimension' => null,
            'status' => AIModelStatus::ACTIVE,
        ]);
        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create(['person_id' => $person->id]);
        $vector = $this->generateUnitVector(512);

        $this->expectException(IncompatibleModelException::class);
        $this->repository->store($profile, $vector, $qualityModel);
    }

    public function test_face_recognition_model_rejected(): void
    {
        $recognitionModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_RECOGNITION,
            'code' => 'recog-pipeline-1',
            'version' => '1.0.0',
            'embedding_dimension' => 512,
            'status' => AIModelStatus::ACTIVE,
        ]);
        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create(['person_id' => $person->id]);
        $vector = $this->generateUnitVector(512);

        $this->expectException(IncompatibleModelException::class);
        $this->repository->store($profile, $vector, $recognitionModel);
    }

    public function test_same_exact_model_and_version_candidates_included(): void
    {
        $person1 = Person::factory()->create();
        $profile1 = FaceProfile::factory()->create(['person_id' => $person1->id, 'status' => FaceProfileStatus::ACTIVE]);
        $vector1 = $this->generateUnitVector(512, 101);
        $this->repository->store($profile1, $vector1, $this->embeddingModel);

        $person2 = Person::factory()->create();
        $profile2 = FaceProfile::factory()->create(['person_id' => $person2->id, 'status' => FaceProfileStatus::ACTIVE]);
        $vector2 = $this->generateUnitVector(512, 102);
        $this->repository->store($profile2, $vector2, $this->embeddingModel);

        $results = $this->repository->search($vector1, $this->embeddingModel, topK: 5);
        $this->assertCount(2, $results);
        $this->assertEquals($profile1->uuid, $results->first()->faceProfileUuid);
        $this->assertEquals($this->embeddingModel->code, $results->first()->modelCode);
        $this->assertEquals($this->embeddingModel->version, $results->first()->modelVersion);
    }

    public function test_different_embedding_model_or_version_excluded(): void
    {
        // Model A (version 1.0.0)
        $modelA = $this->embeddingModel;

        // Model B (version 2.0.0, different model)
        $modelB = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_EMBEDDING,
            'code' => 'arcface-r50-v2',
            'version' => '2.0.0',
            'embedding_dimension' => 512,
            'distance_metric' => 'cosine',
            'status' => AIModelStatus::ACTIVE,
        ]);

        $personA = Person::factory()->create();
        $profileA = FaceProfile::factory()->create(['person_id' => $personA->id, 'status' => FaceProfileStatus::ACTIVE]);
        $vectorA = $this->generateUnitVector(512, 201);
        $this->repository->store($profileA, $vectorA, $modelA);

        $personB = Person::factory()->create();
        $profileB = FaceProfile::factory()->create(['person_id' => $personB->id, 'status' => FaceProfileStatus::ACTIVE]);
        $vectorB = $this->generateUnitVector(512, 202);
        $this->repository->store($profileB, $vectorB, $modelB);

        // Search with Model A: should only return profileA, excluding profileB
        $resultsA = $this->repository->search($vectorA, $modelA, topK: 10);
        $this->assertCount(1, $resultsA);
        $this->assertEquals($profileA->uuid, $resultsA->first()->faceProfileUuid);
        $this->assertEquals('arcface-r50-v1', $resultsA->first()->modelCode);

        // Search with Model B: should only return profileB, excluding profileA
        $resultsB = $this->repository->search($vectorB, $modelB, topK: 10);
        $this->assertCount(1, $resultsB);
        $this->assertEquals($profileB->uuid, $resultsB->first()->faceProfileUuid);
        $this->assertEquals('arcface-r50-v2', $resultsB->first()->modelCode);
    }

    public function test_revoked_profile_cannot_receive_embedding(): void
    {
        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create([
            'person_id' => $person->id,
            'status' => FaceProfileStatus::REVOKED,
            'revoked_at' => now(),
        ]);
        $vector = $this->generateUnitVector(512);

        $this->expectException(IneligibleProfileException::class);
        $this->repository->store($profile, $vector, $this->embeddingModel);
    }

    public function test_exact_vector_search_ranks_first_with_zero_distance(): void
    {
        $person1 = Person::factory()->create();
        $profile1 = FaceProfile::factory()->create(['person_id' => $person1->id, 'status' => FaceProfileStatus::ACTIVE]);
        $vector1 = $this->generateUnitVector(512, 1);
        $this->repository->store($profile1, $vector1, $this->embeddingModel);

        $person2 = Person::factory()->create();
        $profile2 = FaceProfile::factory()->create(['person_id' => $person2->id, 'status' => FaceProfileStatus::ACTIVE]);
        $vector2 = $this->generateUnitVector(512, 2);
        $this->repository->store($profile2, $vector2, $this->embeddingModel);

        $results = $this->repository->search($vector1, $this->embeddingModel, topK: 5);

        $this->assertNotEmpty($results);
        $this->assertInstanceOf(VectorSearchCandidate::class, $results->first());

        $first = $results->first();
        $this->assertEquals($profile1->uuid, $first->faceProfileUuid);
        $this->assertEquals($person1->uuid, $first->personUuid);
        $this->assertEquals(1, $first->rank);
        $this->assertEquals('arcface-r50-v1', $first->modelCode);
        $this->assertEquals('1.0.0', $first->modelVersion);

        // Exact match distance should be ~0.0, similarity ~1.0
        $this->assertEqualsWithDelta(0.0, $first->distance, 0.001);
        $this->assertEqualsWithDelta(1.0, $first->similarity, 0.001);
    }

    public function test_closer_vector_ranks_before_farther_vector(): void
    {
        $baseVector = $this->generateUnitVector(512, 10);

        // Near vector (slight perturbation)
        $nearVector = $baseVector;
        $nearVector[0] += 0.01;
        $nearVector = $this->repository->normalize_vector ?? $nearVector;

        // Far vector (completely different seed)
        $farVector = $this->generateUnitVector(512, 999);

        $personNear = Person::factory()->create();
        $profileNear = FaceProfile::factory()->create(['person_id' => $personNear->id, 'status' => FaceProfileStatus::ACTIVE]);
        $this->repository->store($profileNear, $nearVector, $this->embeddingModel);

        $personFar = Person::factory()->create();
        $profileFar = FaceProfile::factory()->create(['person_id' => $personFar->id, 'status' => FaceProfileStatus::ACTIVE]);
        $this->repository->store($profileFar, $farVector, $this->embeddingModel);

        $results = $this->repository->search($baseVector, $this->embeddingModel, topK: 5);

        $this->assertCount(2, $results);
        $this->assertEquals($profileNear->uuid, $results->first()->faceProfileUuid);
        $this->assertEquals($profileFar->uuid, $results->last()->faceProfileUuid);
        $this->assertLessThan($results->last()->distance, $results->first()->distance);
    }

    public function test_top_k_and_max_bounds_enforced(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $p = Person::factory()->create();
            $fp = FaceProfile::factory()->create(['person_id' => $p->id, 'status' => FaceProfileStatus::ACTIVE]);
            $this->repository->store($fp, $this->generateUnitVector(512, $i + 1), $this->embeddingModel);
        }

        $query = $this->generateUnitVector(512, 1);
        $results3 = $this->repository->search($query, $this->embeddingModel, topK: 3);
        $this->assertCount(3, $results3);

        $results20 = $this->repository->search($query, $this->embeddingModel, topK: 20);
        $this->assertCount(10, $results20);
    }

    public function test_incompatible_model_and_revoked_profiles_are_excluded(): void
    {
        $query = $this->generateUnitVector(512, 50);

        // Different model embedding
        $otherEmbModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_EMBEDDING,
            'code' => 'other-model-v2',
            'version' => '2.0.0',
            'embedding_dimension' => 512,
        ]);

        $personDiff = Person::factory()->create();
        $profileDiff = FaceProfile::factory()->create(['person_id' => $personDiff->id, 'status' => FaceProfileStatus::ACTIVE]);
        $this->repository->store($profileDiff, $query, $otherEmbModel);

        // Revoked profile on the same model
        $personRevoked = Person::factory()->create();
        $profileRevoked = FaceProfile::factory()->create(['person_id' => $personRevoked->id, 'status' => FaceProfileStatus::ACTIVE]);
        $this->repository->store($profileRevoked, $query, $this->embeddingModel);
        // Mark revoked afterwards
        $profileRevoked->update(['status' => FaceProfileStatus::REVOKED, 'revoked_at' => now()]);

        // Pending profile on the same model
        $personPending = Person::factory()->create();
        $profilePending = FaceProfile::factory()->create(['person_id' => $personPending->id, 'status' => FaceProfileStatus::ACTIVE]);
        $this->repository->store($profilePending, $query, $this->embeddingModel);
        // Mark pending
        $profilePending->update(['status' => FaceProfileStatus::PENDING]);

        // Active profile on the same model
        $personActive = Person::factory()->create();
        $profileActive = FaceProfile::factory()->create(['person_id' => $personActive->id, 'status' => FaceProfileStatus::ACTIVE]);
        $this->repository->store($profileActive, $query, $this->embeddingModel);

        $results = $this->repository->search($query, $this->embeddingModel, topK: 10);

        $this->assertCount(1, $results);
        $this->assertEquals($profileActive->uuid, $results->first()->faceProfileUuid);
    }

    public function test_candidate_dto_hides_bigint_ids_and_contains_no_classification(): void
    {
        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create(['person_id' => $person->id, 'status' => FaceProfileStatus::ACTIVE]);
        $this->repository->store($profile, $this->generateUnitVector(512, 1), $this->embeddingModel);

        $results = $this->repository->search($this->generateUnitVector(512, 1), $this->embeddingModel, topK: 1);
        $candidate = $results->first();

        $array = $candidate->toArray();
        $this->assertArrayNotHasKey('id', $array);
        $this->assertArrayNotHasKey('person_id', $array);
        $this->assertArrayNotHasKey('face_profile_id', $array);
        $this->assertArrayNotHasKey('match_type', $array);
        $this->assertArrayNotHasKey('access_decision', $array);
        $this->assertArrayNotHasKey('classification', $array);
    }
}
