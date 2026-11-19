<?php

namespace App\Services\Event;

/**
 * The set of dimensions the policy matcher (EVT-006) evaluates against
 * access_policies — deliberately just data, no behavior, so it stays
 * trivially testable in isolation from AccessEvent/Eloquent.
 *
 * A null subject_type/subject_status/zone_id/event_type is "unknown, not
 * resolvable" (e.g. no person matched, or a matched person with no
 * StudentProfile) — the matcher treats it exactly like an AccessPolicy's
 * own null dimension: it can still match a wildcard policy, just never a
 * policy that specifically requires a value for that dimension.
 *
 * Smart Gate fields (GATE-005, ACR-VEH-01 Phase 5 item 8) are set only for a
 * gate pass (AccessContextResolver::resolvePass) and stay null for an
 * ordinary event, so an ordinary event never matches a gate policy:
 * - gateId / gateMode: the gate the pass went through and its mode (D2);
 * - matchResult: the pass's GateMatchResult value (MATCHED, MISMATCH, ...);
 * - vehicleId / vehicleStatus: the vehicle the plate read matched and its
 *   current registry status (null when the plate was not recognised or the
 *   read was redacted);
 * - ownershipRelation: the relation (OWNER/DRIVER/AUTHORIZED) of the
 *   person's ownership link to that vehicle at the face event's time, or
 *   null when there is no such link. Carried for the decision record; the
 *   matcher reads matchResult, which already says whether the link exists.
 */
final readonly class AccessContext
{
    public function __construct(
        public ?string $subjectType,
        public ?string $subjectStatus,
        public int $siteId,
        public ?int $zoneId,
        public string $eventType,
        public ?int $gateId = null,
        public ?string $gateMode = null,
        public ?string $matchResult = null,
        public ?int $vehicleId = null,
        public ?string $vehicleStatus = null,
        public ?string $ownershipRelation = null,
    ) {}

    public function isGatePass(): bool
    {
        return $this->gateId !== null;
    }
}
