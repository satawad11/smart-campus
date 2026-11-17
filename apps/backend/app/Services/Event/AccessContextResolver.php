<?php

namespace App\Services\Event;

use App\Enums\PersonStatus;
use App\Enums\PersonType;
use App\Enums\VisitStatus;
use App\Models\AccessEvent;
use App\Models\Person;
use App\Services\System\FeatureFlagService;

/** Resolve the canonical subject type/status used by access policy evaluation (PPL-008). */
class AccessContextResolver
{
    public function __construct(private readonly FeatureFlagService $features) {}

    public function resolve(AccessEvent $event): AccessContext
    {
        $subjectType = null;
        $subjectStatus = null;
        $person = $event->person;

        if ($person !== null) {
            $recordInactive = $person->status !== PersonStatus::ACTIVE;
            $student = $person->studentProfile;
            if ($student !== null) {
                $subjectType = 'STUDENT';
                $subjectStatus = $recordInactive
                    ? 'PERSON_INACTIVE'
                    : ($student->academic_status?->value ?? $student->academic_status);
            } elseif (in_array($person->person_type, [PersonType::TEACHER, PersonType::STAFF], true) && ($staff = $person->staffProfile) !== null) {
                $subjectType = 'STAFF';
                $subjectStatus = $recordInactive
                    ? 'PERSON_INACTIVE'
                    : ($staff->employment_status?->value ?? $staff->employment_status);
            } elseif (in_array($person->person_type, [PersonType::VISITOR, PersonType::CONTRACTOR], true)) {
                $subjectType = 'VISITOR';
                $subjectStatus = ! $recordInactive && $this->features->isEnabled('visitor') && $this->hasActiveVisit($person, $event->site_id)
                    ? 'VISIT_ACTIVE'
                    : ($recordInactive ? 'PERSON_INACTIVE' : 'NO_ACTIVE_VISIT');
            } elseif ($person->person_type === PersonType::PARENT) {
                $subjectType = 'PARENT';
                $subjectStatus = $recordInactive
                    ? 'PERSON_INACTIVE'
                    : ($this->hasActiveStudent($person) ? 'ACTIVE' : 'NO_ACTIVE_STUDENT');
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
