<?php

namespace App\Services\Event;

use App\Exceptions\ApiException;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Person;
use Illuminate\Support\Facades\DB;

/**
 * Recognition-event ingestion (EVT-003). Composes an already-computed
 * recognition result (from CAM-011's delivery client) into a durable
 * AccessEvent row — perception only. Does not evaluate policy or persist a
 * decision (EVT-006/EVT-007, not built yet), does not handle a duplicate
 * event_uuid gracefully as an idempotent no-op (EVT-004's scope — this
 * service fails a duplicate cleanly with 409 rather than a raw 500, which
 * is just ordinary error handling, not idempotency), and does not apply
 * semantic cooldown/deduplication (EVT-005).
 */
class RecognitionEventIngestionService
{
    public function ingest(AiNode $resolvedNode, array $data): AccessEvent
    {
        return DB::transaction(function () use ($resolvedNode, $data) {
            if ($data['ai_node_uuid'] !== $resolvedNode->uuid) {
                throw ApiException::unprocessable(
                    'AI_NODE_IDENTITY_MISMATCH',
                    'The reported ai_node_uuid does not match the authenticated node.',
                );
            }

            if (AccessEvent::where('uuid', $data['event_uuid'])->exists()) {
                throw ApiException::conflict(
                    'EVENT_ALREADY_EXISTS',
                    'An event with this event_uuid has already been ingested.',
                );
            }

            $camera = Camera::where('uuid', $data['camera_uuid'])->first()
                ?? throw ApiException::unprocessable('CAMERA_NOT_FOUND', 'The specified camera does not exist.');

            if ($camera->ai_node_id !== $resolvedNode->id) {
                throw ApiException::unprocessable(
                    'CAMERA_NOT_ASSIGNED_TO_NODE',
                    'This AI node is not assigned to the specified camera.',
                );
            }

            $personId = null;
            if ($data['classification'] === 'MATCH' && ! empty($data['candidate_person_uuid'])) {
                $personId = Person::where('uuid', $data['candidate_person_uuid'])->value('id');
            }

            return AccessEvent::create([
                'uuid' => $data['event_uuid'],
                'person_id' => $personId,
                'site_id' => $camera->site_id,
                'zone_id' => $camera->zone_id,
                'camera_id' => $camera->id,
                'ai_node_id' => $resolvedNode->id,
                'event_type' => $this->resolveEventType($data),
                'occurred_at' => $data['occurred_at'],
                'received_at' => now(),
                'face_score' => $data['similarity'] ?? null,
                'liveness_score' => null,
                'quality_score' => $data['quality_score'] ?? null,
                'track_id' => $data['track_id'],
                'snapshot_path' => null,
                'metadata' => [
                    'model_code' => $data['model_code'],
                    'model_version' => $data['model_version'],
                    'classification' => $data['classification'],
                    'distance' => $data['distance'] ?? null,
                    'rejected_locally' => $data['rejected_locally'],
                    'reasons' => $data['reasons'] ?? [],
                ],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveEventType(array $data): string
    {
        if (($data['liveness_status'] ?? null) === 'SPOOF') {
            return 'LIVENESS_FAILED';
        }

        if ($data['classification'] === 'UNKNOWN') {
            return 'UNKNOWN_PERSON';
        }

        return 'FACE_RECOGNIZED';
    }
}
