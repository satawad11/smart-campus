<?php

namespace App\Services\Vehicle;

use App\Events\PlateEventBroadcast;
use App\Exceptions\ApiException;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Vehicle;
use App\Services\Gate\GateCorrelationService;
use App\Services\System\FeatureFlagService;
use App\Support\Vehicles\ThaiPlate;
use App\Support\Vehicles\ThaiProvince;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plate-read ingestion (VEH-010, ACR-VEH-01).
 *
 * Turns a node's plate read into an AccessEvent of type PLATE_RECOGNIZED, PLATE_UNKNOWN or
 * PLATE_LOW_CONFIDENCE. The backend, not the node, normalises the text, resolves the province,
 * looks up the vehicle and picks the type.
 *
 * Records only. A plate event has no person and gets no AccessDecision: a plate never decides
 * access on its own (D2). The Smart Gate correlation (GATE-004) pairs plate and face events.
 *
 * Guards, in order: ALPR enabled for the installation; node identity from the credential;
 * camera assigned to this node and switched to read plates; event_uuid idempotency (same as
 * EVT-004); same-plate cooldown per camera (like EVT-005).
 */
class PlateEventIngestionService
{
    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly GateCorrelationService $gateCorrelation,
    ) {}

    /** @param array<string, mixed> $data */
    public function ingest(AiNode $resolvedNode, array $data): AccessEvent
    {
        if (! $this->flags->isEnabled('alpr')) {
            throw ApiException::unprocessable('ALPR_DISABLED', 'Plate recognition is not enabled for this installation.');
        }

        $created = false;
        $read = $this->interpret($data);

        try {
            $event = DB::transaction(function () use ($resolvedNode, $data, $read, &$created) {
                $camera = $this->assertCamera($resolvedNode, $data);

                $existing = AccessEvent::where('uuid', $data['event_uuid'])->first();
                if ($existing !== null) {
                    return $this->consistentRetryOrFail($existing, $camera, $read['event_type'], $data);
                }

                $recent = $this->findWithinCooldown($camera, $read);
                if ($recent !== null) {
                    return $recent;
                }

                $event = AccessEvent::create([
                    'uuid' => $data['event_uuid'],
                    'person_id' => null,
                    'site_id' => $camera->site_id,
                    'zone_id' => $camera->zone_id,
                    'camera_id' => $camera->id,
                    'ai_node_id' => $resolvedNode->id,
                    'event_type' => $read['event_type'],
                    'occurred_at' => $data['occurred_at'],
                    'received_at' => now(),
                    'track_id' => $data['track_id'],
                    'metadata' => [
                        'model_code' => $data['model_code'],
                        'model_version' => $data['model_version'],
                        'reasons' => $data['reasons'] ?? [],
                        'plate' => $read['plate'],
                    ],
                ]);
                $created = true;

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            // Two concurrent deliveries of one event_uuid: treat the loser like a retry.
            $existing = AccessEvent::where('uuid', $data['event_uuid'])->first();
            $camera = Camera::where('uuid', $data['camera_uuid'])->first();
            if ($existing === null || $camera === null) {
                throw ApiException::conflict('EVENT_ALREADY_EXISTS', 'An event with this event_uuid already exists with different content.');
            }

            return $this->consistentRetryOrFail($existing, $camera, $read['event_type'], $data);
        }

        // After commit, like face events: a broadcast failure never loses the stored event.
        if ($created) {
            try {
                broadcast(new PlateEventBroadcast($event));
            } catch (Throwable $e) {
                Log::warning('Plate event broadcast failed; clients recover via the events API', [
                    'event_uuid' => $event->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
            // GATE-004: pair with a face event at the same Smart Gate. A failure here never
            // loses the stored event; the gates:correlate sweep retries.
            try {
                $this->gateCorrelation->onEventIngested($event);
            } catch (Throwable $e) {
                Log::error('Gate correlation failed', ['event_uuid' => $event->uuid, 'error' => $e->getMessage()]);
            }
        }

        return $event;
    }

    /**
     * Normalise the read and choose the event type. Invalid text or a low OCR score never
     * reaches the registry lookup.
     *
     * @param  array<string, mixed>  $data
     * @return array{event_type: string, plate: array<string, mixed>}
     */
    private function interpret(array $data): array
    {
        $canonical = ThaiPlate::tryNormalize($data['plate_text']);
        $province = ThaiProvince::resolve($data['province_text'] ?? null);
        $score = (float) $data['ocr_score'];
        $vehicle = null;

        if ($canonical === null || $score < (float) config('alpr.ocr_min_confidence')) {
            $type = 'PLATE_LOW_CONFIDENCE';
        } else {
            $vehicle = $this->lookup($canonical, $province);
            $type = $vehicle !== null ? 'PLATE_RECOGNIZED' : 'PLATE_UNKNOWN';
        }

        return [
            'event_type' => $type,
            'plate' => [
                'raw_text' => $data['plate_text'],
                'raw_province' => $data['province_text'] ?? null,
                'plate_number' => $canonical,
                'plate_display' => $canonical !== null ? ThaiPlate::display($canonical) : null,
                'plate_province' => $province,
                'ocr_score' => $score,
                'detection_score' => isset($data['detection_score']) ? (float) $data['detection_score'] : null,
                'frames_voted' => $data['frames_voted'] ?? null,
                'vehicle_uuid' => $vehicle?->uuid,
                'vehicle_status' => $vehicle?->status?->value,
            ],
        ];
    }

    /**
     * Registered (not soft-deleted) vehicle with this plate. With a province: exact match.
     * Without one (province unreadable): only when exactly one vehicle has the plate, so an
     * unreadable province never picks the wrong car.
     */
    private function lookup(string $plate, ?string $province): ?Vehicle
    {
        if ($province !== null) {
            return Vehicle::where('plate_number', $plate)->where('plate_province', $province)->first();
        }

        $matches = Vehicle::where('plate_number', $plate)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @param array<string, mixed> $data */
    private function assertCamera(AiNode $resolvedNode, array $data): Camera
    {
        if ($data['ai_node_uuid'] !== $resolvedNode->uuid) {
            throw ApiException::unprocessable('AI_NODE_IDENTITY_MISMATCH', 'The reported ai_node_uuid does not match the authenticated node.');
        }

        $camera = Camera::where('uuid', $data['camera_uuid'])->first()
            ?? throw ApiException::unprocessable('CAMERA_NOT_FOUND', 'The specified camera does not exist.');

        if ($camera->ai_node_id !== $resolvedNode->id) {
            throw ApiException::unprocessable('CAMERA_NOT_ASSIGNED_TO_NODE', 'This AI node is not assigned to the specified camera.');
        }
        if (! $camera->plate_recognition_enabled) {
            throw ApiException::unprocessable('PLATE_RECOGNITION_DISABLED', 'Plate recognition is switched off for this camera.');
        }

        return $camera;
    }

    /** @param array<string, mixed> $data */
    private function consistentRetryOrFail(AccessEvent $existing, Camera $camera, string $eventType, array $data): AccessEvent
    {
        if ($existing->camera_id === $camera->id && $existing->event_type === $eventType && $existing->track_id === $data['track_id']) {
            return $existing;
        }

        throw ApiException::conflict('EVENT_ALREADY_EXISTS', 'An event with this event_uuid already exists with different content.');
    }

    /**
     * Same readable plate at the same camera within the cooldown = the same vehicle pass.
     * Low-confidence reads are not de-duplicated (they have no reliable plate to key on).
     *
     * @param  array{event_type: string, plate: array<string, mixed>}  $read
     */
    private function findWithinCooldown(Camera $camera, array $read): ?AccessEvent
    {
        $seconds = (int) config('alpr.plate_cooldown_seconds');
        $plate = $read['plate']['plate_number'];
        if ($seconds <= 0 || $plate === null || $read['event_type'] === 'PLATE_LOW_CONFIDENCE') {
            return null;
        }

        return AccessEvent::where('camera_id', $camera->id)
            ->whereIn('event_type', ['PLATE_RECOGNIZED', 'PLATE_UNKNOWN'])
            ->where('occurred_at', '>=', now()->subSeconds($seconds))
            ->orderByDesc('occurred_at')
            ->get()
            ->first(fn (AccessEvent $e) => ($e->metadata['plate']['plate_number'] ?? null) === $plate);
    }
}
