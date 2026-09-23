<?php

namespace App\Services\Event;

use App\Exceptions\ApiException;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Person;
use Illuminate\Support\Facades\DB;

/**
 * Recognition-event ingestion (EVT-003) with event_uuid idempotency
 * (EVT-004), semantic cooldown/deduplication (EVT-005), and decision
 * persistence (EVT-007). Composes an already-computed recognition result
 * (from CAM-011's delivery client) into a durable AccessEvent row —
 * perception only — then, for a genuinely new event only, evaluates policy
 * (EVT-006's AccessContextResolver + AccessPolicyMatcher) and persists the
 * resulting AccessDecision. "Event != Decision" stays true even here: the
 * two are always separate rows, created back-to-back in the same
 * transaction, never merged into one.
 *
 * Three distinct dedup layers, in order:
 *  1. Camera ownership — always enforced, before anything else runs.
 *  2. EVT-004 idempotency: the SAME event_uuid retried (at-least-once
 *     delivery from CAM-012's offline buffer) is a no-op if content
 *     matches, a 409 conflict if it doesn't.
 *  3. EVT-005 semantic cooldown: a genuinely NEW event_uuid that still
 *     describes the same real-world occurrence as a very recent one — a
 *     track lost and re-acquired produces a new track_id/event_uuid on
 *     the AI-node side, but is still the same person at the same camera
 *     — is suppressed (the recent prior event is returned instead of
 *     inserting another row). Keyed camera+person for a resolved MATCH,
 *     camera+track for an UNKNOWN_PERSON event (no person to key on).
 *
 * A retried or cooldown-suppressed event never gets a second decision —
 * only a freshly-created AccessEvent triggers policy evaluation, since the
 * original already has one from when it was first ingested.
 */
class RecognitionEventIngestionService
{
    public function __construct(
        private readonly AccessContextResolver $contextResolver,
        private readonly AccessPolicyMatcher $matcher,
    ) {}

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

            if ($camera->ai_node_id !== $resolvedNode->id) {
                throw ApiException::unprocessable(
                    'CAMERA_NOT_ASSIGNED_TO_NODE',
                    'This AI node is not assigned to the specified camera.',
                );
            }

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

            $personId = null;
            if ($data['classification'] === 'MATCH' && ! empty($data['candidate_person_uuid'])) {
                $personId = Person::where('uuid', $data['candidate_person_uuid'])->value('id');
            }

            $suppressedBy = $this->findWithinSemanticCooldown($camera, $eventType, $personId, $data['track_id']);
            if ($suppressedBy !== null) {
                return $suppressedBy;
            }

            $event = AccessEvent::create([
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

            $this->persistDecision($event);

            return $event;
        });
    }

    /**
     * EVT-007: evaluates policy for a freshly-created event and persists
     * the result as its first AccessDecision. decided_by_user_id stays
     * null — this is a system/policy decision, never an officer's; a
     * manual review later (EVT-010) adds its own separate row without
     * ever touching this one.
     */
    private function persistDecision(AccessEvent $event): void
    {
        $context = $this->contextResolver->resolve($event);
        $result = $this->matcher->match($context);

        AccessDecision::create([
            'event_id' => $event->id,
            'person_id' => $event->person_id,
            'decision' => $result->decision,
            'reason_code' => $result->reasonCode,
            'access_policy_id' => $result->matchedPolicyId,
            'decision_source' => $result->decisionSource,
            'decided_by_user_id' => null,
            'reason_text' => null,
        ]);
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
     * EVT-005: finds a very recent event at this camera that this new one
     * would be a semantic duplicate of, or null if none exists (the event
     * should be created). Person-keyed when a person was resolved (a
     * MATCH); otherwise, for an UNKNOWN_PERSON event, track-keyed — an
     * UNKNOWN event never has a person_id to key on. A REVIEW/MATCH-with-
     * no-candidate/LIVENESS_FAILED event has no cooldown applied: each is
     * either already person-keyed above or specific enough (a liveness
     * failure) that suppressing it could hide a genuine repeated problem.
     */
    private function findWithinSemanticCooldown(Camera $camera, string $eventType, ?int $personId, string $trackId): ?AccessEvent
    {
        if ($personId !== null) {
            $cooldownSeconds = (int) config('event.semantic_cooldown_seconds');
            if ($cooldownSeconds <= 0) {
                return null;
            }

            return AccessEvent::where('camera_id', $camera->id)
                ->where('person_id', $personId)
                ->where('occurred_at', '>=', now()->subSeconds($cooldownSeconds))
                ->orderByDesc('occurred_at')
                ->first();
        }

        if ($eventType === 'UNKNOWN_PERSON') {
            $cooldownSeconds = (int) config('event.unknown_cooldown_seconds');
            if ($cooldownSeconds <= 0) {
                return null;
            }

            return AccessEvent::where('camera_id', $camera->id)
                ->where('track_id', $trackId)
                ->where('event_type', 'UNKNOWN_PERSON')
                ->where('occurred_at', '>=', now()->subSeconds($cooldownSeconds))
                ->orderByDesc('occurred_at')
                ->first();
        }

        return null;
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
