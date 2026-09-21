<?php

namespace Tests\Feature;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\AuditAction;
use App\Enums\DuplicateReviewStatus;
use App\Enums\FaceProfileStatus;
use App\Enums\UserStatus;
use App\Models\AIModel;
use App\Models\FaceDuplicateReview;
use App\Models\FaceProfile;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Comprehensive Feature Tests for Duplicate-Face Review (FACE-011).
 */
class FaceDuplicateReviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;

    protected User $unauthorizedUser;

    protected AIModel $embeddingModel;

    protected AIModel $secondaryEmbeddingModel;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        $viewPerm = Permission::firstOrCreate(
            ['code' => 'face.view'],
            ['name' => 'View Face Profiles', 'module' => 'face_biometrics']
        );
        $enrollPerm = Permission::firstOrCreate(
            ['code' => 'face.enroll'],
            ['name' => 'Enroll and Manage Face Biometrics', 'module' => 'face_biometrics']
        );

        $adminRole = Role::factory()->create([
            'code' => 'face_security_admin',
            'name' => 'Face Security Admin',
        ]);
        $adminRole->permissions()->syncWithoutDetaching([$viewPerm->id, $enrollPerm->id]);

        $this->operator = User::factory()->create([
            'status' => UserStatus::ACTIVE,
        ]);
        $this->operator->roles()->attach($adminRole);

        $this->unauthorizedUser = User::factory()->create([
            'status' => UserStatus::ACTIVE,
        ]);

        // Create default active FACE_EMBEDDING model
        $this->embeddingModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_EMBEDDING,
            'code' => 'arcface-r50-v1',
            'version' => '1.0.0',
            'embedding_dimension' => 512,
            'distance_metric' => 'cosine',
            'status' => AIModelStatus::ACTIVE,
            'threshold_high' => 0.65,
        ]);

        // Create secondary distinct FACE_EMBEDDING model
        $this->secondaryEmbeddingModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_EMBEDDING,
            'code' => 'facenet-512-v2',
            'version' => '2.0.0',
            'embedding_dimension' => 512,
            'distance_metric' => 'cosine',
            'status' => AIModelStatus::ACTIVE,
            'threshold_high' => 0.70,
        ]);

        // Set explicit duplicate review configuration (FACE-021 boundary)
        config([
            'face.duplicate_review.enabled' => true,
            'face.duplicate_review.similarity_threshold' => 0.65,
        ]);
    }

    /** Helper to generate a normalized 512-d unit vector */
    protected function generateVector(float $val = 0.04419417, int $dim = 512): array
    {
        $vec = array_fill(0, $dim, 0.0);
        $vec[0] = $val;

        return $vec;
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

    public function test_unauthenticated_requests_are_denied(): void
    {
        $vec = $this->generateVector(1.0);
        $profile = $this->createEnrolledProfile($vec);

        $response = $this->getJson("/api/v1/faces/{$profile->uuid}/duplicate-review");
        $response->assertStatus(401);
    }

    public function test_unauthorized_requests_without_permission_are_forbidden(): void
    {
        $vec = $this->generateVector(1.0);
        $profile = $this->createEnrolledProfile($vec);

        $response = $this->actingAs($this->unauthorizedUser)
            ->getJson("/api/v1/faces/{$profile->uuid}/duplicate-review");
        $response->assertStatus(403);
    }

    public function test_uuid_only_routes_reject_numeric_ids(): void
    {
        $vec = $this->generateVector(1.0);
        $profile = $this->createEnrolledProfile($vec);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$profile->id}/duplicate-review");
        $response->assertStatus(404);
    }

    public function test_duplicate_review_finds_no_candidate_when_only_self_exists(): void
    {
        $vec = $this->generateVector(1.0);
        $profile = $this->createEnrolledProfile($vec);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$profile->uuid}/duplicate-review");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'source_face_profile_uuid' => $profile->uuid,
                    'source_person_uuid' => $profile->person->uuid,
                    'review_outcome' => 'NO_CANDIDATE',
                    'has_potential_duplicates' => false,
                    'threshold' => 0.65,
                    'candidate_count' => 0,
                    'candidates' => [],
                ],
            ]);
    }

    public function test_duplicate_review_detects_candidate_above_threshold(): void
    {
        // Vector 1 and Vector 2 are identical -> cosine similarity 1.0 (distance 0.0)
        $vec1 = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vec1);

        $candidateProfile = $this->createEnrolledProfile($vec1);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'source_face_profile_uuid' => $sourceProfile->uuid,
                    'source_person_uuid' => $sourceProfile->person->uuid,
                    'review_outcome' => 'REVIEW_REQUIRED',
                    'has_potential_duplicates' => true,
                    'threshold' => 0.65,
                    'candidate_count' => 1,
                    'candidates' => [
                        [
                            'candidate_face_profile_uuid' => $candidateProfile->uuid,
                            'candidate_person_uuid' => $candidateProfile->person->uuid,
                            'review_status' => 'PENDING',
                            'model' => [
                                'code' => 'arcface-r50-v1',
                                'version' => '1.0.0',
                                'type' => 'FACE_EMBEDDING',
                            ],
                        ],
                    ],
                ],
            ]);

        // Ensure duplicate review record exists in DB
        $this->assertDatabaseHas('face_duplicate_reviews', [
            'source_face_profile_id' => $sourceProfile->id,
            'candidate_face_profile_id' => $candidateProfile->id,
            'recognition_model_id' => $this->embeddingModel->id,
            'review_status' => DuplicateReviewStatus::PENDING->value,
        ]);
    }

    public function test_duplicate_review_ignores_candidates_below_threshold(): void
    {
        // Source has vector on dim 0
        $vec1 = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vec1);

        // Candidate has orthogonal vector on dim 1 -> cosine similarity 0.0
        $vec2 = array_fill(0, 512, 0.0);
        $vec2[1] = 1.0;
        $candidateProfile = $this->createEnrolledProfile($vec2);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'source_face_profile_uuid' => $sourceProfile->uuid,
                    'review_outcome' => 'NO_CANDIDATE',
                    'has_potential_duplicates' => false,
                    'candidate_count' => 0,
                    'candidates' => [],
                ],
            ]);

        $this->assertDatabaseMissing('face_duplicate_reviews', [
            'source_face_profile_id' => $sourceProfile->id,
            'candidate_face_profile_id' => $candidateProfile->id,
        ]);
    }

    public function test_duplicate_review_ignores_candidates_with_different_embedding_models(): void
    {
        $vec = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vec, $this->embeddingModel);

        // Candidate has identical vector but enrolled under secondary model
        $candidateProfile = $this->createEnrolledProfile($vec, $this->secondaryEmbeddingModel);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'source_face_profile_uuid' => $sourceProfile->uuid,
                    'review_outcome' => 'NO_CANDIDATE',
                    'has_potential_duplicates' => false,
                    'candidate_count' => 0,
                    'candidates' => [],
                ],
            ]);
    }

    public function test_duplicate_review_ignores_revoked_or_inactive_candidate_profiles(): void
    {
        $vec = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vec, $this->embeddingModel, FaceProfileStatus::ACTIVE);

        // Candidate profile is REVOKED
        $candidateProfile = $this->createEnrolledProfile($vec, $this->embeddingModel, FaceProfileStatus::REVOKED);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'source_face_profile_uuid' => $sourceProfile->uuid,
                    'review_outcome' => 'NO_CANDIDATE',
                    'has_potential_duplicates' => false,
                    'candidate_count' => 0,
                    'candidates' => [],
                ],
            ]);
    }

    public function test_duplicate_review_person_endpoint_works(): void
    {
        $vec = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vec);
        $candidateProfile = $this->createEnrolledProfile($vec);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/persons/{$sourceProfile->person->uuid}/face/duplicate-review");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'source_face_profile_uuid' => $sourceProfile->uuid,
                    'source_person_uuid' => $sourceProfile->person->uuid,
                    'review_outcome' => 'REVIEW_REQUIRED',
                    'has_potential_duplicates' => true,
                ],
            ]);
    }

    public function test_duplicate_review_response_privacy_hides_sensitive_data(): void
    {
        $vec = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vec);
        $candidateProfile = $this->createEnrolledProfile($vec);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review");

        $response->assertStatus(200);
        $jsonString = $response->getContent();

        // Must not expose raw numeric IDs, raw embeddings, or storage paths
        $this->assertStringNotContainsString('"source_face_profile_id"', $jsonString);
        $this->assertStringNotContainsString('"candidate_face_profile_id"', $jsonString);
        $this->assertStringNotContainsString('"recognition_model_id"', $jsonString);
        $this->assertStringNotContainsString('"storage_path"', $jsonString);
        $this->assertStringNotContainsString('"file_path"', $jsonString);
        $this->assertStringNotContainsString('"embedding"', $jsonString);
    }

    public function test_human_decision_updates_status_and_records_audit_log(): void
    {
        $vec = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vec);
        $candidateProfile = $this->createEnrolledProfile($vec);

        // Run duplicate review query to create the review record
        $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review");

        /** @var FaceDuplicateReview $review */
        $review = FaceDuplicateReview::where('source_face_profile_id', $sourceProfile->id)
            ->where('candidate_face_profile_id', $candidateProfile->id)
            ->firstOrFail();

        $this->assertEquals(DuplicateReviewStatus::PENDING, $review->review_status);

        // Submit decision
        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/faces/duplicate-reviews/{$review->uuid}/decision", [
                'decision' => 'CONFIRMED_DUPLICATE',
                'review_note' => 'Identical twin or re-enrollment duplicate',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'uuid' => $review->uuid,
                    'source_face_profile_uuid' => $sourceProfile->uuid,
                    'candidate_face_profile_uuid' => $candidateProfile->uuid,
                    'review_status' => DuplicateReviewStatus::CONFIRMED_DUPLICATE->value,
                    'review_note' => 'Identical twin or re-enrollment duplicate',
                    'reviewed_by_user_uuid' => $this->operator->uuid,
                ],
            ]);

        $review->refresh();
        $this->assertEquals(DuplicateReviewStatus::CONFIRMED_DUPLICATE, $review->review_status);
        $this->assertEquals($this->operator->id, $review->reviewed_by_user_id);
        $this->assertNotNull($review->reviewed_at);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::FACE_DUPLICATE_REVIEWED->value,
            'entity_type' => 'FaceDuplicateReview',
            'entity_id' => $review->uuid,
            'user_id' => $this->operator->id,
            'module' => 'face_biometrics',
        ]);
    }

    public function test_human_decision_does_not_mutate_person_or_face_profile(): void
    {
        $vec = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vec);
        $candidateProfile = $this->createEnrolledProfile($vec);

        $sourcePersonOriginal = $sourceProfile->person->fresh();
        $candidatePersonOriginal = $candidateProfile->person->fresh();

        $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review");

        /** @var FaceDuplicateReview $review */
        $review = FaceDuplicateReview::firstOrFail();

        $this->actingAs($this->operator)
            ->postJson("/api/v1/faces/duplicate-reviews/{$review->uuid}/decision", [
                'decision' => 'CONFIRMED_DUPLICATE',
                'review_note' => 'Confirmed duplicate by security staff',
            ])->assertStatus(200);

        // Invariant: Zero automatic merging or mutation of Person/FaceProfile
        $sourceProfileFresh = $sourceProfile->fresh();
        $candidateProfileFresh = $candidateProfile->fresh();
        $sourcePersonFresh = $sourceProfile->person->fresh();
        $candidatePersonFresh = $candidateProfile->person->fresh();

        $this->assertEquals(FaceProfileStatus::ACTIVE, $sourceProfileFresh->status);
        $this->assertEquals(FaceProfileStatus::ACTIVE, $candidateProfileFresh->status);
        $this->assertEquals($sourcePersonOriginal->uuid, $sourcePersonFresh->uuid);
        $this->assertEquals($candidatePersonOriginal->uuid, $candidatePersonFresh->uuid);
        $this->assertNotEquals($sourcePersonFresh->id, $candidatePersonFresh->id);
    }

    public function test_human_decision_prohibits_client_supplied_internal_fields(): void
    {
        $vec = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vec);
        $candidateProfile = $this->createEnrolledProfile($vec);

        $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review");

        /** @var FaceDuplicateReview $review */
        $review = FaceDuplicateReview::firstOrFail();

        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/faces/duplicate-reviews/{$review->uuid}/decision", [
                'decision' => 'confirmed_duplicate',
                'source_face_profile_id' => 9999,
                'embedding' => [0.1, 0.2],
            ]);

        $response->assertStatus(422);
    }

    public function test_duplicate_review_multiple_candidates_ranked_by_similarity(): void
    {
        $vecSource = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vecSource);

        // Candidate 1: identical vector -> similarity 1.0
        $candidate1 = $this->createEnrolledProfile($vecSource);

        // Candidate 2: slightly altered vector (0.9 on dim 0, 0.43588989 on dim 1 -> cosine sim ~0.9)
        $vecClose = array_fill(0, 512, 0.0);
        $vecClose[0] = 0.9;
        $vecClose[1] = 0.43588989;
        $candidate2 = $this->createEnrolledProfile($vecClose);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review");

        $response->assertStatus(200);
        $candidates = $response->json('data.candidates');

        $this->assertCount(2, $candidates);
        $this->assertEquals($candidate1->uuid, $candidates[0]['candidate_face_profile_uuid']);
        $this->assertEquals($candidate2->uuid, $candidates[1]['candidate_face_profile_uuid']);
        $this->assertGreaterThanOrEqual($candidates[1]['similarity'], $candidates[0]['similarity']);
    }

    public function test_duplicate_review_respects_custom_limit(): void
    {
        $vecSource = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vecSource);

        $this->createEnrolledProfile($vecSource);
        $this->createEnrolledProfile($vecSource);
        $this->createEnrolledProfile($vecSource);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review?limit=2");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.candidates'));
        $this->assertEquals(2, $response->json('data.candidate_count'));
    }

    public function test_duplicate_review_search_is_idempotent_for_database_records(): void
    {
        $vecSource = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vecSource);
        $candidateProfile = $this->createEnrolledProfile($vecSource);

        // First review query
        $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review")
            ->assertStatus(200);

        $this->assertEquals(1, FaceDuplicateReview::count());

        // Second duplicate review query
        $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review")
            ->assertStatus(200);

        // Count should still be 1, no duplicate rows inserted
        $this->assertEquals(1, FaceDuplicateReview::count());
    }

    public function test_duplicate_review_rejects_source_profile_without_embedding(): void
    {
        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create([
            'person_id' => $person->id,
            'status' => FaceProfileStatus::ACTIVE,
            'recognition_model_id' => $this->embeddingModel->id,
            'embedding_dimension' => 512,
            'embedding' => null,
        ]);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$profile->uuid}/duplicate-review");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'EMBEDDING_MISSING',
                ],
            ]);
    }

    public function test_duplicate_review_rejects_non_face_embedding_model(): void
    {
        $detectionModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_DETECTION,
            'code' => 'yolov8-face-v1',
            'version' => '1.0.0',
            'status' => AIModelStatus::ACTIVE,
        ]);

        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create([
            'person_id' => $person->id,
            'status' => FaceProfileStatus::ACTIVE,
            'recognition_model_id' => $detectionModel->id,
            'embedding_dimension' => 512,
        ]);

        $formatted = '['.implode(',', $this->generateVector(1.0)).']';
        DB::statement('UPDATE face_profiles SET embedding = ? WHERE id = ?', [$formatted, $profile->id]);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$profile->fresh()->uuid}/duplicate-review");

        $response->assertStatus(422);
    }

    public function test_explicit_duplicate_review_threshold_from_config_works(): void
    {
        config(['face.duplicate_review.similarity_threshold' => 0.80]);

        // Create pair with similarity ~0.75 (above 0.65 but below 0.80)
        $vecSource = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vecSource);

        $vecCandidate = array_fill(0, 512, 0.0);
        $vecCandidate[0] = 0.75;
        $vecCandidate[1] = 0.6614378;
        $candidateProfile = $this->createEnrolledProfile($vecCandidate);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'threshold' => 0.80,
                    'review_outcome' => 'NO_CANDIDATE',
                    'has_potential_duplicates' => false,
                    'candidate_count' => 0,
                ],
            ]);
    }

    public function test_no_hard_coded_fallback_and_missing_threshold_fails_safely(): void
    {
        // Unset config threshold and ensure model has no metadata threshold
        config(['face.duplicate_review.similarity_threshold' => null]);
        $this->embeddingModel->update(['metadata' => null, 'threshold_high' => null]);

        $vec = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vec);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'DUPLICATE_REVIEW_THRESHOLD_NOT_CONFIGURED',
                ],
            ]);
    }

    public function test_generic_threshold_high_is_not_treated_as_duplicate_policy(): void
    {
        // Unset config threshold; model has generic threshold_high=0.75
        config(['face.duplicate_review.similarity_threshold' => null]);
        $this->embeddingModel->update(['metadata' => null, 'threshold_high' => 0.75]);

        $vec = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vec);

        // Generic threshold_high MUST NOT be silently treated as duplicate review policy
        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'DUPLICATE_REVIEW_THRESHOLD_NOT_CONFIGURED',
                ],
            ]);
    }

    public function test_explicit_model_metadata_duplicate_review_threshold_is_used_when_config_is_null(): void
    {
        // Unset config threshold; model explicitly provides duplicate_review_threshold in metadata
        config(['face.duplicate_review.similarity_threshold' => null]);
        $this->embeddingModel->update([
            'metadata' => ['duplicate_review_threshold' => 0.55],
        ]);

        $vec = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vec);
        $candidateProfile = $this->createEnrolledProfile($vec);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'threshold' => 0.55,
                    'review_outcome' => 'REVIEW_REQUIRED',
                    'has_potential_duplicates' => true,
                ],
            ]);
    }

    public function test_duplicate_review_disabled_feature_flag_returns_no_candidates(): void
    {
        config(['face.duplicate_review.enabled' => false]);

        $vec = $this->generateVector(1.0);
        $sourceProfile = $this->createEnrolledProfile($vec);
        $candidateProfile = $this->createEnrolledProfile($vec);

        $response = $this->actingAs($this->operator)
            ->getJson("/api/v1/faces/{$sourceProfile->uuid}/duplicate-review");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'review_outcome' => 'NO_CANDIDATE',
                    'has_potential_duplicates' => false,
                    'candidate_count' => 0,
                    'candidates' => [],
                ],
            ]);
    }
}
