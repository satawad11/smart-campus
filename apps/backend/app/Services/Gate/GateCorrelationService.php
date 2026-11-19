<?php

namespace App\Services\Gate;

use App\Enums\GateMatchResult;
use App\Enums\LocationStatus;
use App\Models\AccessEvent;
use App\Models\EventType;
use App\Models\Gate;
use App\Models\GateCamera;
use App\Models\GatePass;
use App\Models\Vehicle;
use App\Models\VehicleOwnershipHistory;
use App\Services\System\FeatureFlagService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Face + plate correlation for the Smart Gate (GATE-004, ACR-VEH-01 Phase 5 §7).
 *
 * Puts the face event and the plate event of one pass through a gate together into a
 * GatePass. Correlation only: it never modifies an access event, never writes an
 * AccessDecision (GATE-005..007) and never drives a barrier (D5).
 *
 * Two entry points:
 * - onEventIngested(): called right after a face or plate event is stored. It pairs the event
 *   with an unpaired event of the other kind at the same gate whose occurred_at is within the
 *   gate's correlation_window_seconds. Nothing is created when there is no partner yet.
 * - closeExpired(): the scheduled sweep (gates:correlate). An event still alone once its
 *   window has passed (by server receipt time) gets a FACE_ONLY or PLATE_ONLY pass. It tries
 *   to pair first, so a pairing missed at ingest time is not lost.
 *
 * Which events count: the event's camera is linked to an ACTIVE gate (gate_cameras), the
 * camera's role reads that kind (FACE/BOTH for RECOGNITION events, PLATE/BOTH for PLATE
 * events), the event is in the gate's site and was received after the camera was linked to
 * the gate (received_at, the server's receipt time, set by both ingestion services). Only with
 * feature.vehicle on.
 *
 * Several candidates in the window: the one closest in time wins. Two cars, tailgating and
 * late or out-of-order delivery are GATE-009.
 */
class GateCorrelationService
{
    /** Extra seconds after the window before a lone event is closed (delivery latency). */
    public const CLOSE_GRACE_SECONDS = 5;

    /** The sweep only looks this far back, so turning a gate on never backfills old history. */
    public const LOOKBACK_MINUTES = 15;

    /** @var array<string, list<string>> */
    private array $typesByCategory = [];

    public function __construct(
        private readonly FeatureFlagService $flags,
    ) {}

    /** Try to pair a freshly stored event. Returns the pass when one was created. */
    public function onEventIngested(AccessEvent $event): ?GatePass
    {
        if (! $this->flags->isEnabled('vehicle')) {
            return null;
        }

        return $this->correlate($event, closeAlone: false);
    }

    /**
     * Close lone events whose window has passed. Returns the number of passes created.
     */
    public function closeExpired(?Carbon $now = null): int
    {
        if (! $this->flags->isEnabled('vehicle')) {
            return 0;
        }

        $now ??= now();
        $created = 0;

        $gates = Gate::query()
            ->where('status', LocationStatus::ACTIVE)
            ->with('gateCameras')
            ->get();

        foreach ($gates as $gate) {
            $closeBefore = $now->copy()->subSeconds($gate->correlation_window_seconds + self::CLOSE_GRACE_SECONDS);
            $notBefore = $now->copy()->subMinutes(self::LOOKBACK_MINUTES);

            foreach ($gate->gateCameras as $link) {
                $types = $this->eventTypesFor($link);
                if ($types === []) {
                    continue;
                }

                $pending = $this->unpaired()
                    ->where('camera_id', $link->camera_id)
                    ->where('site_id', $gate->site_id)
                    ->whereIn('event_type', $types)
                    ->where('received_at', '>=', $link->created_at)
                    ->where('received_at', '>=', $notBefore)
                    ->where('received_at', '<=', $closeBefore)
                    ->orderBy('occurred_at')
                    ->orderBy('id')
                    ->get();

                foreach ($pending as $event) {
                    if ($this->correlate($event, closeAlone: true) !== null) {
                        $created++;
                    }
                }
            }
        }

        return $created;
    }

    private function correlate(AccessEvent $event, bool $closeAlone): ?GatePass
    {
        $link = $event->camera_id === null ? null : GateCamera::query()
            ->where('camera_id', $event->camera_id)
            ->with('gate')
            ->first();
        $gate = $link?->gate;

        if ($gate === null || $gate->status !== LocationStatus::ACTIVE || (int) $event->site_id !== (int) $gate->site_id) {
            return null;
        }
        if (! in_array($event->event_type, $this->eventTypesFor($link), true)) {
            return null;
        }
        if ($event->received_at === null || ($link->created_at !== null && $event->received_at->lt($link->created_at))) {
            return null;
        }

        $isFace = in_array($event->event_type, $this->typesOf('RECOGNITION'), true);

        // A retry, a second trigger or the sweep may see an event that is already in a pass.
        if ($this->isPaired($event)) {
            return null;
        }

        $partner = $this->findPartner($gate, $event, $isFace);

        if ($partner !== null) {
            [$face, $plate] = $isFace ? [$event, $partner] : [$partner, $event];

            return $this->createPass($gate, $face, $plate);
        }

        if (! $closeAlone) {
            return null;
        }

        return $isFace
            ? $this->createPass($gate, $event, null)
            : $this->createPass($gate, null, $event);
    }

    /**
     * Unpaired event of the other kind at this gate, within the window, closest in time.
     */
    private function findPartner(Gate $gate, AccessEvent $event, bool $eventIsFace): ?AccessEvent
    {
        $window = $gate->correlation_window_seconds;
        $wantedTypes = $this->typesOf($eventIsFace ? 'PLATE' : 'RECOGNITION');

        /** @var Collection<int, GateCamera> $links */
        $links = GateCamera::query()->where('gate_id', $gate->id)->get()
            ->filter(fn (GateCamera $l) => $eventIsFace ? $l->role->readsPlates() : $l->role->readsFaces());
        if ($links->isEmpty()) {
            return null;
        }

        $candidates = $this->unpaired()
            ->where('site_id', $gate->site_id)
            ->whereIn('event_type', $wantedTypes)
            ->where('id', '!=', $event->id)
            ->whereBetween('occurred_at', [
                $event->occurred_at->copy()->subSeconds($window),
                $event->occurred_at->copy()->addSeconds($window),
            ])
            ->where(function (Builder $q) use ($links) {
                foreach ($links as $l) {
                    $q->orWhere(fn (Builder $c) => $c->where('camera_id', $l->camera_id)->where('received_at', '>=', $l->created_at));
                }
            })
            ->get();

        return $candidates
            ->sortBy(fn (AccessEvent $c) => [abs($c->occurred_at->getTimestamp() - $event->occurred_at->getTimestamp()), $c->id])
            ->first();
    }

    private function createPass(Gate $gate, ?AccessEvent $face, ?AccessEvent $plate): ?GatePass
    {
        $personId = $face?->person_id;
        $vehicle = $plate === null ? null : $this->vehicleOf($plate);

        $result = match (true) {
            $face !== null && $plate !== null => $this->pairResult($face, $plate, $vehicle),
            $face !== null => GateMatchResult::FACE_ONLY,
            default => GateMatchResult::PLATE_ONLY,
        };

        $occurredAt = collect([$face?->occurred_at, $plate?->occurred_at])->filter()->min();

        try {
            // Savepoint: a lost race must not abort a surrounding transaction (PostgreSQL).
            return DB::transaction(fn () => GatePass::create([
                'gate_id' => $gate->id,
                'face_event_id' => $face?->id,
                'plate_event_id' => $plate?->id,
                'person_id' => $personId,
                'vehicle_id' => $vehicle?->id,
                'match_result' => $result,
                'occurred_at' => $occurredAt,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Another trigger linked one of these events first; that pass stands.
            return null;
        }
    }

    private function pairResult(AccessEvent $face, AccessEvent $plate, ?Vehicle $vehicle): GateMatchResult
    {
        $faceKnown = $face->event_type === 'FACE_RECOGNIZED' && $face->person_id !== null;
        $plateKnown = $plate->event_type === 'PLATE_RECOGNIZED' && $vehicle !== null;

        if (! $faceKnown || ! $plateKnown) {
            return GateMatchResult::UNKNOWN;
        }

        $linked = VehicleOwnershipHistory::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('person_id', $face->person_id)
            ->current($face->occurred_at)
            ->exists();

        return $linked ? GateMatchResult::MATCHED : GateMatchResult::MISMATCH;
    }

    /** The vehicle the plate read matched at ingest time (VEH-010); gone once redacted. */
    private function vehicleOf(AccessEvent $plate): ?Vehicle
    {
        $uuid = $plate->metadata['plate']['vehicle_uuid'] ?? null;

        return $uuid === null ? null : Vehicle::withTrashed()->where('uuid', $uuid)->first();
    }

    /** @return Builder<AccessEvent> */
    private function unpaired(): Builder
    {
        return AccessEvent::query()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('gate_passes')->whereColumn('gate_passes.face_event_id', 'access_events.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('gate_passes')->whereColumn('gate_passes.plate_event_id', 'access_events.id'));
    }

    private function isPaired(AccessEvent $event): bool
    {
        return GatePass::query()
            ->where('face_event_id', $event->id)
            ->orWhere('plate_event_id', $event->id)
            ->exists();
    }

    /** @return list<string> event type codes this camera's gate role covers */
    private function eventTypesFor(GateCamera $link): array
    {
        return array_merge(
            $link->role->readsFaces() ? $this->typesOf('RECOGNITION') : [],
            $link->role->readsPlates() ? $this->typesOf('PLATE') : [],
        );
    }

    /** @return list<string> */
    private function typesOf(string $category): array
    {
        return $this->typesByCategory[$category] ??= EventType::query()
            ->where('category', $category)
            ->pluck('code')
            ->all();
    }
}
