<?php

namespace Tests\Feature;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\FaceRecognitionClassification;
use App\Exceptions\Face\FaceEnrollmentException;
use App\Models\AIModel;
use App\Services\Face\DTO\FaceRecognitionTestCandidateDTO;
use App\Services\Face\FaceRecognitionClassificationService;
use App\Services\Face\VectorSearchCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature & Unit Tests for Recognition Classification (FACE-013).
 */
class FaceRecognitionClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected FaceRecognitionClassificationService $classifier;

    protected AIModel $embeddingModel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = app(FaceRecognitionClassificationService::class);

        // Configure approved explicit recognition policy (FACE-021 boundary)
        config([
            'face.recognition.enabled' => true,
            'face.recognition.match_threshold' => 0.80,
            'face.recognition.review_threshold' => 0.60,
            'face.recognition.ambiguity_margin' => 0.05,
        ]);

        $this->embeddingModel = AIModel::factory()->create([
            'model_type' => AIModelType::FACE_EMBEDDING,
            'code' => 'arcface-r50-v1',
            'version' => '1.0.0',
            'embedding_dimension' => 512,
            'distance_metric' => 'cosine',
            'status' => AIModelStatus::ACTIVE,
            'threshold_high' => 0.75, // Generic calibration metadata (NOT policy)
        ]);
    }

    /** Helper to generate candidate DTO */
    protected function createCandidate(float $similarity, int $rank = 1): FaceRecognitionTestCandidateDTO
    {
        $distance = 1.0 - $similarity;

        return new FaceRecognitionTestCandidateDTO(
            candidateFaceProfileUuid: (string) Str::uuid(),
            candidatePersonUuid: (string) Str::uuid(),
            rank: $rank,
            distance: $distance,
            similarity: $similarity,
            model: [
                'code' => 'arcface-r50-v1',
                'version' => '1.0.0',
                'type' => 'FACE_EMBEDDING',
            ],
            status: 'ACTIVE'
        );
    }

    public function test_high_similarity_candidate_above_match_threshold_emits_match(): void
    {
        $candidate = $this->createCandidate(0.85);

        $result = $this->classifier->classify([$candidate], $this->embeddingModel);

        $this->assertEquals(FaceRecognitionClassification::MATCH, $result->classification);
        $this->assertNotNull($result->matchedCandidate);
        $this->assertEquals($candidate->candidateFaceProfileUuid, $result->matchedCandidate->candidateFaceProfileUuid);
        $this->assertContains('HIGH_SIMILARITY_MATCH', $result->reasons);
    }

    public function test_exact_match_threshold_boundary_emits_match(): void
    {
        $candidate = $this->createCandidate(0.80);

        $result = $this->classifier->classify([$candidate], $this->embeddingModel);

        $this->assertEquals(FaceRecognitionClassification::MATCH, $result->classification);
        $this->assertNotNull($result->matchedCandidate);
    }

    public function test_similarity_in_review_band_emits_review(): void
    {
        // 0.70 is between review_threshold (0.60) and match_threshold (0.80)
        $candidate = $this->createCandidate(0.70);

        $result = $this->classifier->classify([$candidate], $this->embeddingModel);

        $this->assertEquals(FaceRecognitionClassification::REVIEW, $result->classification);
        $this->assertNull($result->matchedCandidate);
        $this->assertContains('SIMILARITY_IN_REVIEW_BAND', $result->reasons);
    }

    public function test_exact_review_threshold_boundary_emits_review(): void
    {
        $candidate = $this->createCandidate(0.60);

        $result = $this->classifier->classify([$candidate], $this->embeddingModel);

        $this->assertEquals(FaceRecognitionClassification::REVIEW, $result->classification);
        $this->assertNull($result->matchedCandidate);
        $this->assertContains('SIMILARITY_IN_REVIEW_BAND', $result->reasons);
    }

    public function test_similarity_below_review_threshold_emits_unknown(): void
    {
        // 0.59 is below review_threshold (0.60)
        $candidate = $this->createCandidate(0.59);

        $result = $this->classifier->classify([$candidate], $this->embeddingModel);

        $this->assertEquals(FaceRecognitionClassification::UNKNOWN, $result->classification);
        $this->assertNull($result->matchedCandidate);
        $this->assertContains('NO_CANDIDATE_ABOVE_THRESHOLD', $result->reasons);
    }

    public function test_empty_candidates_emits_unknown(): void
    {
        $result = $this->classifier->classify([], $this->embeddingModel);

        $this->assertEquals(FaceRecognitionClassification::UNKNOWN, $result->classification);
        $this->assertNull($result->matchedCandidate);
        $this->assertContains('NO_CANDIDATES_FOUND', $result->reasons);
        $this->assertEmpty($result->candidates);
    }

    public function test_ambiguous_top_candidates_within_margin_emits_review(): void
    {
        // Candidate 1: 0.85 (above match_threshold)
        // Candidate 2: 0.83 (diff = 0.02 < ambiguity_margin 0.05)
        $candidate1 = $this->createCandidate(0.85, 1);
        $candidate2 = $this->createCandidate(0.83, 2);

        $result = $this->classifier->classify([$candidate1, $candidate2], $this->embeddingModel);

        $this->assertEquals(FaceRecognitionClassification::REVIEW, $result->classification);
        $this->assertNull($result->matchedCandidate);
        $this->assertContains('AMBIGUOUS_CANDIDATES', $result->reasons);
    }

    public function test_ambiguous_top_candidates_outside_margin_emits_match(): void
    {
        // Candidate 1: 0.85 (above match_threshold)
        // Candidate 2: 0.70 (diff = 0.15 >= ambiguity_margin 0.05)
        $candidate1 = $this->createCandidate(0.85, 1);
        $candidate2 = $this->createCandidate(0.70, 2);

        $result = $this->classifier->classify([$candidate1, $candidate2], $this->embeddingModel);

        $this->assertEquals(FaceRecognitionClassification::MATCH, $result->classification);
        $this->assertNotNull($result->matchedCandidate);
        $this->assertEquals($candidate1->candidateFaceProfileUuid, $result->matchedCandidate->candidateFaceProfileUuid);
    }

    public function test_missing_recognition_threshold_configuration_fails_closed(): void
    {
        config([
            'face.recognition.match_threshold' => null,
            'face.recognition.review_threshold' => null,
        ]);

        $this->embeddingModel->update(['metadata' => null]);
        $candidate = $this->createCandidate(0.90);

        $this->expectException(FaceEnrollmentException::class);
        $this->expectExceptionMessage('Recognition policy thresholds are not configured');

        $this->classifier->classify([$candidate], $this->embeddingModel);
    }

    public function test_invalid_threshold_ordering_where_match_less_than_review_throws_exception(): void
    {
        config([
            'face.recognition.match_threshold' => 0.50,
            'face.recognition.review_threshold' => 0.70,
        ]);

        $candidate = $this->createCandidate(0.90);

        $this->expectException(FaceEnrollmentException::class);
        $this->expectExceptionMessage('cannot be less than review threshold');

        $this->classifier->classify([$candidate], $this->embeddingModel);
    }

    public function test_generic_threshold_high_is_not_treated_as_recognition_policy(): void
    {
        config([
            'face.recognition.match_threshold' => null,
            'face.recognition.review_threshold' => null,
        ]);

        // Model has generic threshold_high
        $this->embeddingModel->update(['threshold_high' => 0.80, 'metadata' => null]);
        $candidate = $this->createCandidate(0.90);

        $this->expectException(FaceEnrollmentException::class);
        $this->expectExceptionMessage('Generic AIModel threshold_high cannot be used without explicit configuration');

        $this->classifier->classify([$candidate], $this->embeddingModel);
    }

    public function test_explicit_model_metadata_thresholds_used_when_config_is_null(): void
    {
        config([
            'face.recognition.match_threshold' => null,
            'face.recognition.review_threshold' => null,
        ]);

        $this->embeddingModel->update([
            'metadata' => [
                'recognition_match_threshold' => 0.75,
                'recognition_review_threshold' => 0.55,
            ],
        ]);

        $candidate = $this->createCandidate(0.78);

        $result = $this->classifier->classify([$candidate], $this->embeddingModel);

        $this->assertEquals(FaceRecognitionClassification::MATCH, $result->classification);
        $this->assertEquals(0.75, $result->thresholds['match_threshold']);
        $this->assertEquals(0.55, $result->thresholds['review_threshold']);
    }

    public function test_zero_access_decisions_emitted_in_result(): void
    {
        $candidate = $this->createCandidate(0.90);

        $result = $this->classifier->classify([$candidate], $this->embeddingModel);
        $array = $result->toArray();

        $this->assertArrayNotHasKey('access_decision', $array);
        $this->assertArrayNotHasKey('decision', $array);
        $this->assertArrayNotHasKey('allow', $array);
        $this->assertArrayNotHasKey('deny', $array);
    }

    public function test_vector_search_candidate_collection_normalization(): void
    {
        $vectorCandidate = new VectorSearchCandidate(
            faceProfileUuid: (string) Str::uuid(),
            personUuid: (string) Str::uuid(),
            modelCode: 'arcface-r50-v1',
            modelVersion: '1.0.0',
            distance: 0.15,
            similarity: 0.85,
            rank: 1,
            status: 'ACTIVE'
        );

        $result = $this->classifier->classify(collect([$vectorCandidate]), $this->embeddingModel);

        $this->assertEquals(FaceRecognitionClassification::MATCH, $result->classification);
        $this->assertEquals($vectorCandidate->faceProfileUuid, $result->matchedCandidate->candidateFaceProfileUuid);
    }
}
