<?php

namespace App\Services\Face;

use App\Enums\AIModelType;
use App\Enums\FaceProfileStatus;
use App\Exceptions\Face\EmbeddingDimensionMismatchException;
use App\Exceptions\Face\IncompatibleModelException;
use App\Exceptions\Face\IneligibleProfileException;
use App\Exceptions\Face\InvalidVectorException;
use App\Models\AIModel;
use App\Models\FaceProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Biometric pgvector repository and candidate search service (FACE-008).
 *
 * Invariants:
 * - Directs vector search queries against exact compatible AI models (AIModelType::FACE_EMBEDDING).
 * - Requires exact model identity match between stored embeddings and query vector.
 * - recognition_model_id schema column points to the exact model that produced the stored embedding.
 * - Restricts candidate search to eligible ACTIVE FaceProfiles only (excludes REVOKED/PENDING).
 * - Enforces parameterized vector bindings to prevent raw SQL injection.
 * - Hides raw vector data from candidate search results and API resources.
 * - Operates strictly as candidate retrieval (rank, distance, similarity, UUIDs).
 * - Does NOT execute identity classification (MATCH/REVIEW/UNKNOWN - owned by FACE-013).
 * - Does NOT execute access decisions (ALLOW/REVIEW/DENY - owned by Module 05 Event + Policy).
 * - Does NOT execute profile revocation or re-enrollment lifecycle (owned by FACE-014).
 */
class FaceEmbeddingRepository
{
    public const DEFAULT_TOP_K = 5;

    public const MAX_TOP_K = 50;

    /**
     * Store a biometric embedding vector onto a FaceProfile.
     *
     * @param  array<int, float>  $vector
     *
     * @throws IncompatibleModelException
     * @throws IneligibleProfileException
     * @throws EmbeddingDimensionMismatchException
     * @throws InvalidVectorException
     */
    public function store(FaceProfile $profile, array $vector, AIModel $model): FaceProfile
    {
        $this->assertModelCompatible($model);

        if ($profile->status === FaceProfileStatus::REVOKED) {
            throw new IneligibleProfileException(
                "Cannot attach embedding to revoked FaceProfile [{$profile->uuid}]."
            );
        }

        $expectedDimension = $model->embedding_dimension ?? 512;
        $formattedVector = $this->validateAndFormatVector($vector, $expectedDimension);

        return DB::transaction(function () use ($profile, $model, $formattedVector, $expectedDimension) {
            $driver = DB::getDriverName();

            if ($driver === 'pgsql') {
                DB::statement(
                    'UPDATE face_profiles SET recognition_model_id = ?, embedding_dimension = ?, embedding = CAST(? AS vector), updated_at = NOW() WHERE id = ?',
                    [$model->id, $expectedDimension, $formattedVector, $profile->id]
                );
            } else {
                $profile->recognition_model_id = $model->id;
                $profile->embedding_dimension = $expectedDimension;
                $profile->embedding = $formattedVector;
                $profile->save();
            }

            return $profile->fresh();
        });
    }

    /**
     * Search for nearest candidate biometric embeddings using cosine distance.
     *
     * @param  array<int, float>  $queryVector
     * @return Collection<int, VectorSearchCandidate>
     *
     * @throws IncompatibleModelException
     * @throws EmbeddingDimensionMismatchException
     * @throws InvalidVectorException
     */
    public function search(
        array $queryVector,
        AIModel $model,
        int $topK = self::DEFAULT_TOP_K,
        ?float $maxDistance = null
    ): Collection {
        $this->assertModelCompatible($model);

        $expectedDimension = $model->embedding_dimension ?? 512;
        $formattedVector = $this->validateAndFormatVector($queryVector, $expectedDimension);

        $boundedTopK = min(max($topK, 1), self::MAX_TOP_K);
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            return $this->searchPostgres($formattedVector, $model, $boundedTopK, $maxDistance);
        }

        return $this->searchFallback($queryVector, $model, $boundedTopK, $maxDistance);
    }

    /**
     * Execute native PostgreSQL pgvector cosine distance search.
     *
     * @return Collection<int, VectorSearchCandidate>
     */
    protected function searchPostgres(
        string $formattedVector,
        AIModel $model,
        int $topK,
        ?float $maxDistance
    ): Collection {
        $query = '
            SELECT
                fp.uuid AS face_profile_uuid,
                p.uuid AS person_uuid,
                m.code AS model_code,
                m.version AS model_version,
                (fp.embedding <=> CAST(? AS vector)) AS distance,
                fp.status
            FROM face_profiles fp
            JOIN persons p ON fp.person_id = p.id
            JOIN ai_models m ON fp.recognition_model_id = m.id
            WHERE fp.recognition_model_id = ?
              AND fp.status = ?
              AND fp.embedding IS NOT NULL
        ';

        $bindings = [$formattedVector, $model->id, FaceProfileStatus::ACTIVE->value];

        if ($maxDistance !== null) {
            $query .= ' AND (fp.embedding <=> CAST(? AS vector)) <= ?';
            $bindings[] = $formattedVector;
            $bindings[] = $maxDistance;
        }

        $query .= ' ORDER BY (fp.embedding <=> CAST(? AS vector)) ASC, fp.id ASC LIMIT ?';
        $bindings[] = $formattedVector;
        $bindings[] = $topK;

        $rows = DB::select($query, $bindings);

        $candidates = collect();
        foreach ($rows as $index => $row) {
            $distance = (float) $row->distance;
            $similarity = (float) max(-1.0, min(1.0, 1.0 - $distance));

            $candidates->push(new VectorSearchCandidate(
                faceProfileUuid: (string) $row->face_profile_uuid,
                personUuid: (string) $row->person_uuid,
                modelCode: (string) $row->model_code,
                modelVersion: (string) $row->model_version,
                distance: $distance,
                similarity: $similarity,
                rank: $index + 1,
                status: FaceProfileStatus::tryFrom($row->status) ?? $row->status,
            ));
        }

        return $candidates;
    }

    /**
     * Fallback in-memory cosine distance search for SQLite testing environments.
     *
     * @param  array<int, float>  $queryVector
     * @return Collection<int, VectorSearchCandidate>
     */
    protected function searchFallback(
        array $queryVector,
        AIModel $model,
        int $topK,
        ?float $maxDistance
    ): Collection {
        $profiles = FaceProfile::query()
            ->with(['person', 'recognitionModel'])
            ->where('recognition_model_id', $model->id)
            ->where('status', FaceProfileStatus::ACTIVE)
            ->whereNotNull('embedding')
            ->get();

        $scored = [];

        foreach ($profiles as $profile) {
            if (! $profile->person || ! $profile->recognitionModel) {
                continue;
            }

            $rawEmbedding = $profile->embedding;
            $candidateVector = $this->parseVectorString($rawEmbedding);

            if (count($candidateVector) !== count($queryVector)) {
                continue;
            }

            $distance = $this->computeCosineDistance($queryVector, $candidateVector);

            if ($maxDistance !== null && $distance > $maxDistance) {
                continue;
            }

            $scored[] = [
                'profile' => $profile,
                'distance' => $distance,
            ];
        }

        // Sort ascending by distance, tie-break by ID
        usort($scored, function ($a, $b) {
            if (abs($a['distance'] - $b['distance']) < 1e-7) {
                return $a['profile']->id <=> $b['profile']->id;
            }

            return $a['distance'] <=> $b['distance'];
        });

        $topScored = array_slice($scored, 0, $topK);

        $candidates = collect();
        foreach ($topScored as $index => $item) {
            $profile = $item['profile'];
            $distance = (float) $item['distance'];
            $similarity = (float) max(-1.0, min(1.0, 1.0 - $distance));

            $candidates->push(new VectorSearchCandidate(
                faceProfileUuid: $profile->uuid,
                personUuid: $profile->person->uuid,
                modelCode: $profile->recognitionModel->code,
                modelVersion: $profile->recognitionModel->version,
                distance: $distance,
                similarity: $similarity,
                rank: $index + 1,
                status: $profile->status,
            ));
        }

        return $candidates;
    }

    /**
     * Compute cosine distance between two float vectors (1 - cosine_similarity).
     *
     * @param  array<int, float>  $u
     * @param  array<int, float>  $v
     */
    protected function computeCosineDistance(array $u, array $v): float
    {
        $dot = 0.0;
        $normU = 0.0;
        $normV = 0.0;
        $len = count($u);

        for ($i = 0; $i < $len; $i++) {
            $dot += $u[$i] * $v[$i];
            $normU += $u[$i] * $u[$i];
            $normV += $v[$i] * $v[$i];
        }

        $denom = sqrt($normU) * sqrt($normV);
        if ($denom < 1e-9) {
            return 1.0;
        }

        $similarity = $dot / $denom;

        return max(0.0, 1.0 - $similarity);
    }

    /**
     * Parse formatted vector string [0.1,0.2,...] to float array.
     *
     * @return array<int, float>
     */
    protected function parseVectorString(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_map('floatval', $raw);
        }

        if (! is_string($raw)) {
            return [];
        }

        $clean = trim($raw, " \t\n\r\0\x0B[]");
        if ($clean === '') {
            return [];
        }

        return array_map('floatval', explode(',', $clean));
    }

    /**
     * Validate vector components and return SQL vector literal format.
     *
     * @param  array<int, float>  $vector
     *
     * @throws EmbeddingDimensionMismatchException
     * @throws InvalidVectorException
     */
    protected function validateAndFormatVector(array $vector, int $expectedDimension): string
    {
        if (empty($vector)) {
            throw new InvalidVectorException('Embedding vector cannot be empty.');
        }

        if (count($vector) !== $expectedDimension) {
            throw new EmbeddingDimensionMismatchException(
                'Embedding vector dimension mismatch: got '.count($vector).", expected {$expectedDimension}."
            );
        }

        $normSq = 0.0;
        $formatted = [];

        foreach ($vector as $idx => $val) {
            if (! is_numeric($val) || ! is_finite((float) $val) || is_nan((float) $val)) {
                throw new InvalidVectorException(
                    "Embedding vector contains non-finite or NaN value at index {$idx}."
                );
            }

            $floatVal = (float) $val;
            $normSq += $floatVal * $floatVal;
            $formatted[] = (string) $floatVal;
        }

        if ($normSq < 1e-9) {
            throw new InvalidVectorException('Embedding vector has zero norm and cannot be indexed.');
        }

        return '['.implode(',', $formatted).']';
    }

    /**
     * Assert model type compatibility.
     *
     * Invariant: Biometric vector persistence and candidate search are strictly
     * restricted to AIModelType::FACE_EMBEDDING. Models with other types
     * (FACE_DETECTION, FACE_ALIGNMENT, FACE_QUALITY, FACE_RECOGNITION) are rejected.
     *
     * @throws IncompatibleModelException
     */
    protected function assertModelCompatible(AIModel $model): void
    {
        if ($model->model_type !== AIModelType::FACE_EMBEDDING) {
            throw new IncompatibleModelException(
                "AIModel [{$model->code}] has incompatible model type [{$model->model_type->value}] for face embedding operations. Expected [".AIModelType::FACE_EMBEDDING->value.'].'
            );
        }
    }
}
