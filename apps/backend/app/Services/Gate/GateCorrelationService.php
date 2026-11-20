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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Face + plate correlation for the Smart Gate (GATE-004, ACR-VEH-01 Phase 5 §7).
 *
 * Puts the face event and the plate event of one pass through a gate together into a
 * GatePass. It never modifies an access event and never drives a barrier (D5). Each new
 * pass goes to GateDecisionService (GATE-007), which writes the pass's AccessDecision.
 *
 * Two entry points:
 * - onEventIngested(): called right after a face or plate event is stored. It pairs the event
 *   at once only when the choice is unambiguous: exactly one unpaired event of the other kind
 *   within the gate's correlation_window_seconds, and no other unpaired gate event near either.
 *   Otherwise (two cars close together, tailgating) it leaves the choice to the sweep.
 * - closeExpired(): the scheduled sweep (gates:correlate). Per gate it matches all unpaired
 *   events in passing order (GATE-009, see assign()) and writes the passes whose events have all
 *   had their window plus grace since they were received; an event left without a partner gets
 *   a FACE_ONLY or PLATE_ONLY pass. A pairing missed at ingest time is therefore not lost.
 *
 * Which events count: the event's camera is linked to an ACTIVE gate (gate_cameras), the
 * camera's role reads that kind (FACE/BOTH for RECOGNITION events, PLATE/BOTH for PLATE
 * events), the event is in the gate's site and was received after the camera was linked to
 * the gate (received_at, the server's receipt time, set by both ingestion services). Only with
 * feature.vehicle on.
 *
 * GATE-009 edge cases:
 * - Two cars or two people close together: cars pass a lane in order, so faces and plates are
 *   matched without crossing (the first face with the first plate), choosing the matching with
 *   the least total time apart, where leaving an event alone costs a whole window.
 * - Tailgating (a car with no face seen) gives a PLATE_ONLY pass, which is set to REVIEW and
 *   alerts (owner choice 2026-11-20: no separate tailgating label).
 * - Out of order: the order events arrive in does not matter; pairing uses occurred_at.
 * - Late: an event received more than STALE_SECONDS after it happened (a node that was offline)
 *   skips the gate and is handled like an event at an ordinary camera (owner choice 2026-11-20).
 *   A pass already written is never reopened for a late partner: passes are immutable.
 */
class GateCorrelationService
{
    /** Extra seconds after the window before a lone event is closed (delivery latency). */
    public const CLOSE_GRACE_SECONDS = 5;

    /** The sweep only looks this far back, so turning a gate on never backfills old history. */
    public const LOOKBACK_MINUTES = 15;

    /** GATE-009: an event delivered later than this after it happened skips the gate. */
    public const STALE_SECONDS = 120;

    /** Safety cap on the events matched per gate in one sweep. */
    private const MAX_PENDING_PER_GATE = 300;

    /** @var array<string, list<string>> */
    private array $typesByCategory = [];

    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly GateDecisionService $decisions,
    ) {}

    /**
     * True when this event is handled by a Smart Gate: it will end up in a pass that gets its own
     * decision and alert (GATE-007). The ingestion services skip the event's own notification
     * then, so officers get one alert per pass (owner choice 2026-11-20).
     */
    public function covers(AccessEvent $event): bool
    {
        return $this->flags->isEnabled('vehicle') && $this->gateFor($event) !== null;
    }

    /** Try to pair a freshly stored event. Returns the pass when one was created. */
    public function onEventIngested(AccessEvent $event): ?GatePass
    {
        if (! $this->flags->isEnabled('vehicle')) {
            return null;
        }

        return $this->correlate($event);
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
            ->get();

        foreach ($gates as $gate) {
            $closeBefore = $now->copy()->subSeconds($gate->correlation_window_seconds + self::CLOSE_GRACE_SECONDS);
            $notBefore = $now->copy()->subMinutes(self::LOOKBACK_MINUTES);

            $pending = $this->gateEvents($gate)
                ->where('received_at', '>=', $notBefore)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit(self::MAX_PENDING_PER_GATE)
                ->get()
                ->reject(fn (AccessEvent $e) => $this->isStale($e))
                ->values();

            $ready = fn (?AccessEvent $e) => $e === null || $e->received_at->lte($closeBefore);
            foreach ($this->assign($pending, $gate->correlation_window_seconds) as [$face, $plate]) {
                // Wait until every event of the pass has had its window: a later arrival may still matter.
                if (! $ready($face) || ! $ready($plate)) {
                    continue;
                }
                $pass = $this->createPass($gate, $face, $plate);
                if ($pass !== null) {
                    $created++;
                    $this->decide($pass);
                }
            }
        }

        // GATE-007: a pass whose decision failed right after creation is decided here.
        $this->decisions->decideUndecided($now->copy()->subMinutes(self::LOOKBACK_MINUTES));

        return $created;
    }

    /**
     * The ACTIVE gate this event counts for, or null: camera linked to the gate, camera role reads
     * this kind of event, event in the gate's site and received after the camera was linked.
     */
    private function gateFor(AccessEvent $event): ?Gate
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
        if ($this->isStale($event)) {
            return null;
        }

        return $gate;
    }

    /** Ingest time: pair now only when the choice is unambiguous (GATE-009); else the sweep decides. */
    private function correlate(AccessEvent $event): ?GatePass
    {
        $gate = $this->gateFor($event);
        if ($gate === null) {
            return null;
        }

        // A retry or a second trigger may see an event that is already in a pass.
        if ($this->isPaired($event)) {
            return null;
        }

        $isFace = in_array($event->event_type, $this->typesOf('RECOGNITION'), true);
        $partner = $this->soleNearbyPartner($gate, $event, $isFace);
        if ($partner === null) {
            return null;
        }

        [$face, $plate] = $isFace ? [$event, $partner] : [$partner, $event];
        $pass = $this->createPass($gate, $face, $plate);
        if ($pass !== null) {
            $this->decide($pass);
        }

        return $pass;
    }

    /** GATE-007. A failure is retried by the sweep (decideUndecided). */
    private function decide(GatePass $pass): void
    {
        try {
            $this->decisions->decide($pass);
        } catch (Throwable $e) {
            Log::error('Gate decision failed', ['gate_pass_uuid' => $pass->uuid, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The one unpaired event of the other kind within the window, provided no other unpaired gate
     * event of either kind is within the window of the two. Null when there is none or a choice.
     */
    private function soleNearbyPartner(Gate $gate, AccessEvent $event, bool $eventIsFace): ?AccessEvent
    {
        $window = $gate->correlation_window_seconds;
        $nearby = $this->gateEvents($gate)
            ->whereBetween('occurred_at', [
                $event->occurred_at->copy()->subSeconds(2 * $window),
                $event->occurred_at->copy()->addSeconds(2 * $window),
            ])
            ->get()
            ->reject(fn (AccessEvent $e) => $e->id === $event->id || $this->isStale($e));

        $otherKind = $this->typesOf($eventIsFace ? 'PLATE' : 'RECOGNITION');
        $candidates = $nearby->filter(fn (AccessEvent $e) => in_array($e->event_type, $otherKind, true)
            && $this->millisApart($e, $event) <= $window * 1000);
        if ($candidates->count() !== 1) {
            return null;
        }

        $partner = $candidates->first();
        $from = $event->occurred_at->min($partner->occurred_at)->copy()->subSeconds($window);
        $to = $event->occurred_at->max($partner->occurred_at)->copy()->addSeconds($window);
        $others = $nearby->filter(fn (AccessEvent $e) => $e->id !== $partner->id
            && $e->occurred_at->betweenIncluded($from, $to));

        return $others->isEmpty() ? $partner : null;
    }

    /**
     * GATE-009: match the faces and plates of one gate in passing order. Both lists are in
     * occurred_at order and pairs never cross (cars pass a lane one after another). Among such
     * matchings the one with the least total time apart wins, where an event left alone costs a
     * whole window, so a pair is only made within the window. Returns [face|null, plate|null]
     * in passing order.
     *
     * @param  Collection<int, AccessEvent>  $events  unpaired gate events, in occurred_at order
     * @return list<array{0: ?AccessEvent, 1: ?AccessEvent}>
     */
    private function assign(Collection $events, int $window): array
    {
        $faceTypes = $this->typesOf('RECOGNITION');
        $isFace = fn (AccessEvent $e) => in_array($e->event_type, $faceTypes, true);
        $faces = $events->filter($isFace)->values()->all();
        $plates = $events->reject($isFace)->values()->all();
        $n = count($faces);
        $m = count($plates);
        $alone = $window * 1000;
        $owns = $this->ownershipCheck($faces, $plates);

        // cost[i][j]: best total for the first i faces and j plates; step[i][j]: the last move.
        $cost = [[0]];
        $step = [];
        for ($i = 0; $i <= $n; $i++) {
            for ($j = 0; $j <= $m; $j++) {
                if ($i === 0 && $j === 0) {
                    continue;
                }
                $best = PHP_INT_MAX;
                $how = null;
                if ($i > 0 && $j > 0) {
                    $apart = $this->millisApart($faces[$i - 1], $plates[$j - 1]);
                    // The car's own driver costs nothing, so a passenger never takes the car.
                    $pairCost = $owns($faces[$i - 1], $plates[$j - 1]) ? 0 : $apart;
                    if ($apart <= $alone && $cost[$i - 1][$j - 1] + $pairCost < $best) {
                        [$best, $how] = [$cost[$i - 1][$j - 1] + $pairCost, 'pair'];
                    }
                }
                if ($i > 0 && $cost[$i - 1][$j] + $alone < $best) {
                    [$best, $how] = [$cost[$i - 1][$j] + $alone, 'face'];
                }
                if ($j > 0 && $cost[$i][$j - 1] + $alone < $best) {
                    [$best, $how] = [$cost[$i][$j - 1] + $alone, 'plate'];
                }
                $cost[$i][$j] = $best;
                $step[$i][$j] = $how;
            }
        }

        $passes = [];
        for ($i = $n, $j = $m; $i > 0 || $j > 0;) {
            $how = $step[$i][$j];
            if ($how === 'pair') {
                $passes[] = [$faces[--$i], $plates[--$j]];
            } elseif ($how === 'face') {
                $passes[] = [$faces[--$i], null];
            } else {
                $passes[] = [null, $plates[--$j]];
            }
        }

        $first = fn (array $pass) => collect($pass)->filter()
            ->sortBy(fn (AccessEvent $e) => [$e->occurred_at->format('U.u'), $e->id])->first();
        usort($passes, fn (array $a, array $b) => [$first($a)->occurred_at->format('U.u'), $first($a)->id]
            <=> [$first($b)->occurred_at->format('U.u'), $first($b)->id]);

        return $passes;
    }

    /**
     * Whether a face's person has a current ownership link to a plate's vehicle at the face's time
     * (the MATCHED rule of pairResult()), loaded in one query for all candidates.
     *
     * @param  list<AccessEvent>  $faces
     * @param  list<AccessEvent>  $plates
     * @return \Closure(AccessEvent, AccessEvent): bool
     */
    private function ownershipCheck(array $faces, array $plates): \Closure
    {
        $vehicleIds = [];
        foreach ($plates as $plate) {
            if ($plate->event_type === 'PLATE_RECOGNIZED' && ($vehicle = $this->vehicleOf($plate)) !== null) {
                $vehicleIds[$plate->id] = $vehicle->id;
            }
        }
        $personIds = collect($faces)
            ->filter(fn (AccessEvent $f) => $f->event_type === 'FACE_RECOGNIZED' && $f->person_id !== null)
            ->pluck('person_id')->unique()->values()->all();

        $links = ($vehicleIds === [] || $personIds === []) ? collect() : VehicleOwnershipHistory::query()
            ->whereIn('vehicle_id', array_values($vehicleIds))
            ->whereIn('person_id', $personIds)
            ->get(['person_id', 'vehicle_id', 'effective_from', 'effective_to']);

        return function (AccessEvent $face, AccessEvent $plate) use ($vehicleIds, $links): bool {
            $vehicleId = $vehicleIds[$plate->id] ?? null;
            if ($vehicleId === null || $face->event_type !== 'FACE_RECOGNIZED' || $face->person_id === null) {
                return false;
            }
            $at = $face->occurred_at;

            return $links->contains(fn ($l) => (int) $l->person_id === (int) $face->person_id
                && (int) $l->vehicle_id === (int) $vehicleId
                && $l->effective_from->lte($at)
                && ($l->effective_to === null || $l->effective_to->gt($at)));
        };
    }

    private function millisApart(AccessEvent $a, AccessEvent $b): int
    {
        return (int) abs($a->occurred_at->diffInMilliseconds($b->occurred_at));
    }

    /** GATE-009: delivered more than STALE_SECONDS after it happened (e.g. from a node's offline buffer). */
    private function isStale(AccessEvent $event): bool
    {
        return $event->received_at !== null && $event->occurred_at !== null
            && $event->received_at->getTimestamp() - $event->occurred_at->getTimestamp() > self::STALE_SECONDS;
    }

    /**
     * Unpaired events at this gate: from a linked camera whose role reads that kind, received after
     * the camera was linked, in the gate's site. Matches nothing for a gate without cameras.
     *
     * @return Builder<AccessEvent>
     */
    private function gateEvents(Gate $gate): Builder
    {
        $links = GateCamera::query()->where('gate_id', $gate->id)->get();

        return $this->unpaired()
            ->where('site_id', $gate->site_id)
            ->where(function (Builder $q) use ($links) {
                $q->whereRaw('1 = 0');
                foreach ($links as $link) {
                    $types = $this->eventTypesFor($link);
                    if ($types !== []) {
                        $q->orWhere(fn (Builder $c) => $c->where('camera_id', $link->camera_id)
                            ->whereIn('event_type', $types)
                            ->where('received_at', '>=', $link->created_at));
                    }
                }
            });
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
