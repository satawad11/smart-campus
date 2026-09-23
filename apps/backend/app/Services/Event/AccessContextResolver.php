<?php

namespace App\Services\Event;

use App\Models\AccessEvent;

/**
 * Resolves an AccessEvent into the AccessContext the policy matcher
 * (EVT-006) needs — the "resolve student" step of docs/09's processing
 * pipeline (`... -> resolve person -> resolve student -> ... -> evaluate
 * policy -> ...`). subject_type is hard-coded 'STUDENT' when a resolved
 * person has a StudentProfile — the only subject domain that exists today
 * (every other domain is explicitly locked pending approval, TASKS.md
 * "FUTURE — LOCKED UNTIL APPROVAL"); a person with no StudentProfile (or
 * no person at all, e.g. an UNKNOWN_PERSON event) resolves to a null
 * subject_type/subject_status, matching only wildcard policies.
 */
class AccessContextResolver
{
    public function resolve(AccessEvent $event): AccessContext
    {
        $subjectType = null;
        $subjectStatus = null;

        if ($event->person_id !== null) {
            $studentProfile = $event->person?->studentProfile;
            if ($studentProfile !== null) {
                $subjectType = 'STUDENT';
                $subjectStatus = $studentProfile->academic_status?->value ?? $studentProfile->academic_status;
            }
        }

        return new AccessContext(
            subjectType: $subjectType,
            subjectStatus: $subjectStatus,
            siteId: $event->site_id,
            zoneId: $event->zone_id,
            eventType: $event->event_type,
        );
    }
}
