<?php

namespace App\Services\Event;

use App\Exceptions\ApiException;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Person;
use Illuminate\Support\Facades\DB;

/**
 * Recognition-event ingestion (EVT-003) with event_uuid idempotency
 * (EVT-004). Composes an already-computed recognition result (from
 * CAM-011's delivery client) into a durable AccessEvent row — perception
 * only. Does not evaluate policy or persist a decision (EVT-006/EVT-007,
 * not built yet), and does not apply semantic cooldown/deduplication
 * (EVT-005, a different concern: that's about collapsing distinct-but-
 * near-duplicate recognitions, not about a single delivery being retried).
 *
 * Idempotency (docs/09 "Deduplication: event_uuid unique"): a retried
 * delivery of the same event_uuid with materially the same content (same
 * camera, same derived event_type, same track_id) is a no-op — the
 * already-stored event is returned unchanged, not re-created, and the
 * caller (CAM-012's offline-buffer retry, in particular — that mechanism
 * guarantees at-least-once delivery, so a duplicate is an expected, normal
 * occurrence, not an error). A retry whose content genuinely differs is a
 * real conflict (the same event_uuid reused for a different event) and is
 * rejected with 409, exactly as it was before this task.
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

            $camera = Camera::where('uuid', $data['camera_uuid'])->first()
                ?? throw ApiException::unprocessable('CAMERA_NOT_FOUND', 'The specified camera does not exist.');

            $eventType = $this->resolveEventType($data);

            $existing = AccessEvent::where('uuid', $data['event_uuid'])->first();
            if ($existing !== null) {
                if ($this->isConsistentRetry($existing, $camera, $eventType, $data)) {
                    return $existing;
                }

                throw ApiException::conflict(
                    'EVENT_ALREADY_EXISTS',
                    'An event with this event_uuid already exists with different content.',
                );
            }

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
                'event_type' => $eventType,
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
     * True if a re-submitted event_uuid describes the same occurrence as
     * the already-stored one — camera, derived event_type, and track_id
     * all match. Deliberately not an exhaustive field-by-field diff (e.g.
     * received_at will always differ, being the ingest-time timestamp of
     * each attempt, and is never part of the retried payload anyway) —
     * just enough to distinguish "the same delivery, retried" from "a
     * different event whose generator reused a UUID", which is the only
     * thing idempotency needs to tell apart.
     *
     * @param  array<string, mixed>  $data
     */
    private function isConsistentRetry(AccessEvent $existing, Camera $camera, string $eventType, array $data): bool
    {
        return $existing->camera_id === $camera->id
            && $existing->event_type === $eventType
            && $existing->track_id === $data['track_id'];
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
