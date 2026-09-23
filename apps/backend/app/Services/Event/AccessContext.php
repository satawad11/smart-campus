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
 */
final readonly class AccessContext
{
    public function __construct(
        public ?string $subjectType,
        public ?string $subjectStatus,
        public int $siteId,
        public ?int $zoneId,
        public string $eventType,
    ) {}
}
