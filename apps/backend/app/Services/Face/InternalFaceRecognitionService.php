<?php

namespace App\Services\Face;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Exceptions\Face\IncompatibleModelException;
use App\Models\AIModel;
use App\Services\Face\DTO\FaceRecognitionClassificationResult;

/**
 * Internal (AI-node-facing) candidate search + classification (CAM-009).
 *
 * Composes two already-existing Module 03 services — FaceEmbeddingRepository
 * (pgvector search) and FaceRecognitionClassificationService (FACE-013
 * canonical policy classification) — for a vector the AI node already
 * computed locally. No detection/alignment/quality/embedding algorithm is
 * reimplemented here; this class only resolves the model and wires the two
 * existing services together.
 *
 * Deliberately NOT audited to the security audit_logs table: this runs at
 * camera-processing volume (potentially many times per second per node),
 * which is routine perception traffic, not a discrete administrative event —
 * the same reasoning already applied to AI node heartbeats (CAM-003).
 * The meaningful, audited record is the resulting AccessEvent once Module 05
 * (EVT-007) persists a delivered recognition event; that boundary is
 * unchanged by this endpoint's existence.
 */
class InternalFaceRecognitionService
{
    public function __construct(
        private readonly FaceEmbeddingRepository $embeddingRepository,
        private readonly FaceRecognitionClassificationService $classificationService,
    ) {}

    /**
     * @param  list<float>  $embedding
     */
    public function recognize(
        array $embedding,
        string $modelCode,
        string $modelVersion,
        ?int $topK = null,
    ): FaceRecognitionClassificationResult {
        $model = $this->resolveModel($modelCode, $modelVersion);

        $boundTopK = min(
            max($topK ?? FaceEmbeddingRepository::DEFAULT_TOP_K, 1),
            FaceEmbeddingRepository::MAX_TOP_K
        );

        $candidates = $this->embeddingRepository->search(
            queryVector: $embedding,
            model: $model,
            topK: $boundTopK,
        );

        return $this->classificationService->classify($candidates, $model);
    }

    private function resolveModel(string $code, string $version): AIModel
    {
        $model = AIModel::query()
            ->where('model_type', AIModelType::FACE_EMBEDDING)
            ->where('status', AIModelStatus::ACTIVE)
            ->where('code', $code)
            ->where('version', $version)
            ->first();

        if ($model === null) {
            throw new IncompatibleModelException(
                "No active FACE_EMBEDDING model registered for code [{$code}] version [{$version}]."
            );
        }

        return $model;
    }
}
