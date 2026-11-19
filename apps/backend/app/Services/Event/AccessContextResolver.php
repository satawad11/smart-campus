<?php

namespace App\Services\Event;

use App\Enums\PersonType;
use App\Enums\VisitStatus;
use App\Models\AccessEvent;
use App\Models\GatePass;
use App\Models\Person;
use App\Models\Vehicle;
use App\Models\VehicleOwnershipHistory;
use App\Services\System\FeatureFlagService;

/** Resolve the canonical subject type/status used by access policy evaluation (PPL-008). */
class AccessContextResolver
{
    public function __construct(private readonly FeatureFlagService $features) {}

    public function resolve(AccessEvent $event): AccessContext
    {
        [$subjectType, $subjectStatus] = $this->subjectOf($event->person, $event->site_id);

        return new AccessContext(
            subjectType: $subjectType,
            subjectStatus: $subjectStatus,
            siteId: $event->site_id,
            zoneId: $event->zone_id,
            eventType: $event->event_type,
        );
    }

    /**
     * Context for a Smart Gate pass (GATE-005, ACR-VEH-01 Phase 5 item 8). Read-only.
     *
     * The face stays the identity (D2): the subject comes from the face event's person only, never
     * from the vehicle's owner, so a plate alone leaves the subject unknown. Site and zone are the
     * gate's. eventType is the face event's type, or the plate event's for a PLATE_ONLY pass.
     */
    public function resolvePass(GatePass $pass): AccessContext
    {
        $gate = $pass->gate;
        $face = $pass->faceEvent;
        $primary = $face ?? $pass->plateEvent;

        [$subjectType, $subjectStatus] = $this->subjectOf($face?->person, $gate->site_id);

        $vehicle = $pass->vehicle_id === null ? null : Vehicle::withTrashed()->find($pass->vehicle_id);

        return new AccessContext(
            subjectType: $subjectType,
            subjectStatus: $subjectStatus,
            siteId: $gate->site_id,
            zoneId: $gate->zone_id,
            eventType: $primary->event_type,
            gateId: $gate->id,
            gateMode: $gate->mode->value,
            matchResult: $pass->match_result->value,
            vehicleId: $vehicle?->id,
            vehicleStatus: $vehicle?->status?->value,
            ownershipRelation: $this->ownershipRelation($face, $pass->person_id, $vehicle),
        );
    }

    /** @return array{0: ?string, 1: ?string} [subject_type, subject_status] */
    private function subjectOf(?Person $person, int $siteId): array
    {
        if ($person === null) {
            return [null, null];
        }

        $student = $person->studentProfile;
        if ($student !== null) {
            return ['STUDENT', $student->academic_status?->value ?? $student->academic_status];
        }
        if (in_array($person->person_type, [PersonType::TEACHER, PersonType::STAFF], true) && ($staff = $person->staffProfile) !== null) {
            return ['STAFF', $staff->employment_status?->value ?? $staff->employment_status];
        }
        if (in_array($person->person_type, [PersonType::VISITOR, PersonType::CONTRACTOR], true)) {
            return ['VISITOR', $this->features->isEnabled('visitor') && $this->hasActiveVisit($person, $siteId)
                ? 'VISIT_ACTIVE'
                : 'NO_ACTIVE_VISIT'];
        }
        if ($person->person_type === PersonType::PARENT) {
            return ['PARENT', $this->hasActiveStudent($person) ? 'ACTIVE' : 'NO_ACTIVE_STUDENT'];
        }

        return [null, null];
    }

    /** Relation of the person's link to the vehicle at the face event's time, if any. */
    private function ownershipRelation(?AccessEvent $face, ?int $personId, ?Vehicle $vehicle): ?string
    {
        if ($face === null || $personId === null || $vehicle === null) {
            return null;
        }

        $link = VehicleOwnershipHistory::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('person_id', $personId)
            ->current($face->occurred_at)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $link?->relation?->value;
    }

    private function hasActiveVisit(Person $person, int $siteId): bool
    {
        return $person->visits()
            ->where('site_id', $siteId)
            ->where(function ($query) {
                $query->where('status', VisitStatus::CHECKED_IN->value)
                    ->orWhere(fn ($scheduled) => $scheduled
                        ->where('status', VisitStatus::SCHEDULED->value)
                        ->where('valid_from', '<=', now())
                        ->where('valid_until', '>=', now()));
            })
            ->exists();
    }

    private function hasActiveStudent(Person $person): bool
    {
        return $person->guardianOf()
            ->whereHas('student', fn ($student) => $student
                ->where('status', 'ACTIVE')
                ->whereHas('studentProfile', fn ($profile) => $profile->where('academic_status', 'ACTIVE')))
            ->exists();
    }
}
