<?php

namespace App\Services\Face;

use App\Enums\AuditAction;
use App\Enums\FaceRecognitionClassification;
use App\Exceptions\Face\FaceEnrollmentException;
use App\Exceptions\Face\FaceQualityRejectedException;
use App\Exceptions\Face\InvalidImageException;
use App\Exceptions\Face\MultipleFacesDetectedException;
use App\Exceptions\Face\NoFaceDetectedException;
use App\Models\Person;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use App\Services\Face\DTO\FaceRecognitionTestCandidateDTO;
use Illuminate\Http\UploadedFile;

/**
 * Webcam face scanning (FACE-022; docs/02-api-contract.md §8 check-quality / recognize).
 *
 * Composes existing Module 03 services only — no detection, embedding, search or
 * classification logic is re-implemented here:
 *   check-quality: AI detection + quality, for live capture indicators (no embedding).
 *   recognize:     AI pipeline -> liveness (FACE-015) -> pgvector top-K (FACE-008)
 *                  -> MATCH/REVIEW/UNKNOWN (FACE-013).
 *
 * Boundaries:
 * - Biometric identification only. No AccessEvent, no AccessDecision, no door action.
 * - Raw embeddings and image bytes never leave this service; nothing is persisted
 *   except the audit row of a recognize call.
 * - Recognition searches only profiles of the exact model/version that produced the
 *   probe vector (InternalFaceRecognitionService), never across models.
 */
class FaceScanService
{
    public const SCAN_TOP_K = 5;

    public function __construct(
        protected FacePipelineClient $pipelineClient,
        protected InternalFaceRecognitionService $recognitionService,
        protected FaceLivenessService $livenessService,
        protected AuditService $auditService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function checkQuality(UploadedFile $image): array
    {
        [$bytes, $mime] = $this->readImage($image);
        $raw = $this->pipelineClient->checkQuality($bytes, $mime);

        $quality = isset($raw['quality']) && is_array($raw['quality']) ? $raw['quality'] : null;

        return [
            'face_count' => (int) ($raw['face_count'] ?? 0),
            'faces' => array_values(array_map(fn ($f) => [
                'x1' => (float) ($f['x1'] ?? 0),
                'y1' => (float) ($f['y1'] ?? 0),
                'x2' => (float) ($f['x2'] ?? 0),
                'y2' => (float) ($f['y2'] ?? 0),
                'confidence' => (float) ($f['confidence'] ?? 0),
            ], array_filter((array) ($raw['faces'] ?? []), 'is_array'))),
            'image_width' => (int) ($raw['image_width'] ?? 0),
            'image_height' => (int) ($raw['image_height'] ?? 0),
            'accepted' => (bool) ($raw['accepted'] ?? false),
            'rejection_reasons' => array_values(array_map('strval', (array) ($raw['rejection_reasons'] ?? []))),
            'quality' => $quality === null ? null : [
                'quality_score' => (float) ($quality['quality_score'] ?? 0),
                'blur_score' => (float) ($quality['blur_score'] ?? 0),
                'brightness_score' => (float) ($quality['brightness_score'] ?? 0),
                'face_size_score' => (float) ($quality['face_size_score'] ?? 0),
                'pose_score' => (float) ($quality['pose_score'] ?? 0),
                'yaw' => isset($quality['yaw']) ? (float) $quality['yaw'] : null,
                'pitch' => isset($quality['pitch']) ? (float) $quality['pitch'] : null,
                'roll' => isset($quality['roll']) ? (float) $quality['roll'] : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws FaceEnrollmentException
     */
    public function recognize(UploadedFile $image, ?User $actor = null, ?AuditRequestContext $context = null): array
    {
        [$bytes, $mime] = $this->readImage($image);

        $pipeline = $this->pipelineClient->processEnrollment($bytes, $mime, 'FRONT', 'CAMERA');

        if ($pipeline->faceCount === 0) {
            throw new NoFaceDetectedException('No face detected in the scanned image.');
        }
        if ($pipeline->faceCount > 1) {
            throw new MultipleFacesDetectedException(
                $pipeline->faceCount,
                "{$pipeline->faceCount} faces detected. Exactly 1 face is required to scan."
            );
        }
        if (! $pipeline->qualityAccepted) {
            throw new FaceQualityRejectedException(
                $pipeline->rejectionReasons,
                ['quality_score' => $pipeline->qualityScore],
                'Scanned face does not meet quality requirements.'
            );
        }

        $liveness = $this->evaluateLiveness($bytes);

        $result = $this->recognitionService->recognize(
            embedding: $pipeline->embedding,
            modelCode: $pipeline->modelCode,
            modelVersion: $pipeline->modelVersion,
            topK: self::SCAN_TOP_K,
        );

        $people = $this->loadPeople(array_map(
            fn (FaceRecognitionTestCandidateDTO $c) => $c->candidatePersonUuid,
            $result->candidates
        ));

        $candidates = array_map(fn (FaceRecognitionTestCandidateDTO $c) => [
            'rank' => $c->rank,
            'similarity' => round($c->similarity, 4),
            'face_profile_uuid' => $c->candidateFaceProfileUuid,
            'person' => $people[$c->candidatePersonUuid] ?? ['uuid' => $c->candidatePersonUuid],
        ], $result->candidates);

        $matched = null;
        if ($result->classification === FaceRecognitionClassification::MATCH && $result->matchedCandidate) {
            $m = $result->matchedCandidate;
            $matched = [
                'similarity' => round($m->similarity, 4),
                'face_profile_uuid' => $m->candidateFaceProfileUuid,
                'person' => $people[$m->candidatePersonUuid] ?? ['uuid' => $m->candidatePersonUuid],
            ];
        }

        $this->auditService->record(
            action: AuditAction::FACE_RECOGNITION_TESTED,
            actor: $actor,
            subjectType: $matched ? 'Person' : 'FaceRecognitionTest',
            subjectUuid: $matched['person']['uuid'] ?? null,
            metadata: [
                'mode' => 'LIVE_SCAN',
                'classification' => $result->classification->value,
                'model_code' => $result->model['code'],
                'model_version' => $result->model['version'],
                'candidate_count' => count($candidates),
                'quality_score' => $pipeline->qualityScore,
                'liveness_status' => $liveness['status'],
            ],
            context: $context,
            module: 'face_biometrics'
        );

        return [
            'classification' => $result->classification->value,
            'reasons' => $result->reasons,
            'matched' => $matched,
            'candidates' => $candidates,
            'liveness' => $liveness,
            'face' => [
                'bounding_box' => $pipeline->boundingBox,
                'quality_score' => $pipeline->qualityScore,
            ],
            'thresholds' => [
                'match_threshold' => $result->thresholds['match_threshold'],
                'review_threshold' => $result->thresholds['review_threshold'],
            ],
            'model' => $result->model,
            'executed_at' => $result->executedAt,
        ];
    }

    /**
     * PAD result for display. A PAD outage must not hide the identification result,
     * so it is reported as UNAVAILABLE instead of failing the scan; this endpoint never
     * grants access, Module 05 policy owns that.
     *
     * @return array{status: string, score: float|null, is_live: bool|null}
     */
    protected function evaluateLiveness(string $bytes): array
    {
        try {
            $r = $this->livenessService->evaluate($bytes);

            return ['status' => $r->status->value, 'score' => round($r->livenessScore, 4), 'is_live' => $r->isLive];
        } catch (FaceEnrollmentException) {
            return ['status' => 'UNAVAILABLE', 'score' => null, 'is_live' => null];
        }
    }

    /**
     * Safe person summaries for result display (no internal ids, no biometrics).
     *
     * @param  list<string>  $uuids
     * @return array<string, array<string, mixed>>
     */
    protected function loadPeople(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        return Person::query()
            ->with('studentProfile')
            ->whereIn('uuid', array_unique($uuids))
            ->get()
            ->mapWithKeys(fn (Person $p) => [$p->uuid => [
                'uuid' => $p->uuid,
                'person_code' => $p->person_code,
                'display_name' => $p->display_name ?: trim("{$p->first_name} {$p->last_name}"),
                'person_type' => $p->person_type?->value ?? $p->person_type,
                'student_code' => $p->studentProfile?->student_code,
            ]])
            ->all();
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function readImage(UploadedFile $image): array
    {
        if (! $image->isValid()) {
            throw new InvalidImageException('Uploaded image file is invalid or corrupted.');
        }

        $bytes = (string) file_get_contents($image->getRealPath());
        if ($bytes === '') {
            throw new InvalidImageException('Uploaded image is empty.');
        }

        return [$bytes, $image->getMimeType() ?? 'image/jpeg'];
    }
}
