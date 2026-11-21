<?php

namespace Database\Seeders;

use App\Models\AccessPolicy;
use Illuminate\Database\Seeder;

/**
 * Seeds the documented default access policies (EVT-002,
 * docs/09-module-05-event-policy-monitor.md "Default Policy Concept").
 *
 * Idempotent: updateOrCreate keyed on `name`, matching PermissionSeeder's
 * (AUTH-003) established convention. The matcher that actually evaluates
 * these is EVT-006 — this seeder only establishes the documented starting
 * configuration; policies remain fully configuration-driven/editable
 * afterward (docs/09: "Policies are configuration-driven").
 *
 * priority: lower value evaluated first (see the access_policies creating
 * migration's docblock). LIVENESS_FAILED and UNKNOWN are evaluated ahead
 * of the subject-status policies (priority 10 vs 100/200) since neither
 * depends on — or should be overridden by — a resolved student's status.
 * Group-specific baseline policies are evaluated after event exceptions.
 */
class AccessPolicySeeder extends Seeder
{
    public function run(): void
    {
        // Keep legacy pilot rows for decision-history references, but prevent
        // their former ALLOW rules from participating in new evaluations.
        AccessPolicy::query()
            ->whereIn('name', ['Visitor Active Visit -> Allow', 'Parent With Active Student -> Allow'])
            ->update(['enabled' => false]);

        foreach ($this->getDefaultPolicies() as $policy) {
            AccessPolicy::updateOrCreate(
                ['name' => $policy['name']],
                $policy,
            );
        }

        // Smart Gate templates are created once and then left alone: an admin enables them per
        // site, and reseeding must not switch them back off.
        foreach ($this->getGateTemplatePolicies() as $policy) {
            AccessPolicy::firstOrCreate(['name' => $policy['name']], $policy);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDefaultPolicies(): array
    {
        return [
            [
                'name' => 'Liveness Failed -> Review',
                'subject_type' => null,
                'subject_status' => null,
                'site_id' => null,
                'zone_id' => null,
                'event_type' => 'LIVENESS_FAILED',
                'decision' => 'REVIEW',
                'reason_code' => 'LIVENESS_FAILED',
                'priority' => 10,
                'enabled' => true,
            ],
            [
                'name' => 'Unrecognized Person -> Unknown',
                'subject_type' => null,
                'subject_status' => null,
                'site_id' => null,
                'zone_id' => null,
                'event_type' => 'UNKNOWN_PERSON',
                'decision' => 'UNKNOWN',
                'reason_code' => 'RECOGNITION_UNKNOWN',
                'priority' => 10,
                'enabled' => true,
            ],
            [
                'name' => 'Student Active -> Allow',
                'subject_type' => 'STUDENT',
                'subject_status' => 'ACTIVE',
                'site_id' => null,
                'zone_id' => null,
                'event_type' => null,
                'decision' => 'ALLOW',
                'reason_code' => 'STUDENT_ACTIVE',
                'priority' => 200,
                'enabled' => true,
            ],
            [
                'name' => 'Student Leave -> Review',
                'subject_type' => 'STUDENT',
                'subject_status' => 'LEAVE',
                'site_id' => null,
                'zone_id' => null,
                'event_type' => null,
                'decision' => 'REVIEW',
                'reason_code' => 'STUDENT_LEAVE',
                'priority' => 100,
                'enabled' => true,
            ],
            [
                'name' => 'Student Suspended -> Review',
                'subject_type' => 'STUDENT',
                'subject_status' => 'SUSPENDED',
                'site_id' => null,
                'zone_id' => null,
                'event_type' => null,
                'decision' => 'REVIEW',
                'reason_code' => 'STUDENT_SUSPENDED',
                'priority' => 100,
                'enabled' => true,
            ],
            [
                'name' => 'Student Withdrawn -> Review',
                'subject_type' => 'STUDENT',
                'subject_status' => 'WITHDRAWN',
                'site_id' => null,
                'zone_id' => null,
                'event_type' => null,
                'decision' => 'REVIEW',
                'reason_code' => 'STUDENT_WITHDRAWN',
                'priority' => 100,
                'enabled' => true,
            ],
            [
                'name' => 'Student Graduated -> Review',
                'subject_type' => 'STUDENT',
                'subject_status' => 'GRADUATED',
                'site_id' => null,
                'zone_id' => null,
                'event_type' => null,
                'decision' => 'REVIEW',
                'reason_code' => 'STUDENT_GRADUATED',
                'priority' => 100,
                'enabled' => true,
            ],
            [
                'name' => 'Student Expelled -> Deny',
                'subject_type' => 'STUDENT',
                'subject_status' => 'EXPELLED',
                'site_id' => null,
                'zone_id' => null,
                'event_type' => null,
                'decision' => 'DENY',
                'reason_code' => 'STUDENT_EXPELLED',
                'priority' => 100,
                'enabled' => true,
            ],
            [
                'name' => 'Student Person Inactive -> Review',
                'subject_type' => 'STUDENT',
                'subject_status' => 'PERSON_INACTIVE',
                'site_id' => null,
                'zone_id' => null,
                'event_type' => null,
                'decision' => 'REVIEW',
                'reason_code' => 'PERSON_RECORD_INACTIVE',
                'priority' => 100,
                'enabled' => true,
            ],
            [
                'name' => 'Staff Active -> Allow',
                'subject_type' => 'STAFF',
                'subject_status' => 'ACTIVE',
                'site_id' => null,
                'zone_id' => null,
                'event_type' => null,
                'decision' => 'ALLOW',
                'reason_code' => 'STAFF_ACTIVE',
                'priority' => 200,
                'enabled' => true,
            ],
            ...$this->reviewPolicies('STAFF', ['LEAVE', 'SUSPENDED', 'RESIGNED', 'RETIRED'], 'STAFF'),
            [
                'name' => 'Staff Person Inactive -> Review',
                'subject_type' => 'STAFF',
                'subject_status' => 'PERSON_INACTIVE',
                'site_id' => null,
                'zone_id' => null,
                'event_type' => null,
                'decision' => 'REVIEW',
                'reason_code' => 'PERSON_RECORD_INACTIVE',
                'priority' => 100,
                'enabled' => true,
            ],
            [
                'name' => 'Visitor Active Visit -> Review',
                'subject_type' => 'VISITOR',
                'subject_status' => 'VISIT_ACTIVE',
                'site_id' => null,
                'zone_id' => null,
                'event_type' => null,
                'decision' => 'REVIEW',
                'reason_code' => 'VISITOR_ACTIVE_VISIT_REVIEW',
                'priority' => 100,
                'enabled' => true,
            ],
            ...$this->reviewPolicies('VISITOR', ['NO_ACTIVE_VISIT'], 'VISITOR'),
            [
                'name' => 'Parent With Active Student -> Review',
                'subject_type' => 'PARENT',
                'subject_status' => 'ACTIVE',
                'site_id' => null,
                'zone_id' => null,
                'event_type' => null,
                'decision' => 'REVIEW',
                'reason_code' => 'PARENT_ACTIVE_STUDENT_REVIEW',
                'priority' => 100,
                'enabled' => true,
            ],
            ...$this->reviewPolicies('PARENT', ['NO_ACTIVE_STUDENT'], 'PARENT'),
            ...$this->getGatePolicies(),
        ];
    }

    /**
     * FACE_PRIMARY Smart Gate policies (GATE-006, ACR-VEH-01 decision D2). They apply only to gate
     * passes (gate_mode is set), never to an ordinary face event.
     *
     * - A BLOCKED vehicle is denied whoever drives it (priority 1, ahead of everything).
     * - A plate alone, an unknown face or a failed liveness check goes to review (priority 5,
     *   ahead of the event-type rules at 10). A plate never lets anyone in.
     * - A recognised face is deliberately not covered here: it falls through to the person's own
     *   policies, so an entitled person is allowed whatever the plate says (D2 "ALLOW with
     *   note"; the note is the pass's match_result) and an inactive, suspended or expelled person
     *   keeps their REVIEW or DENY. No ALLOW is seeded for gates at all.
     *
     * @return list<array<string, mixed>>
     */
    public function getGatePolicies(): array
    {
        return [
            $this->gatePolicy('Gate Vehicle Blocked -> Deny', 'DENY', 'GATE_VEHICLE_BLOCKED', 1, vehicleStatus: 'BLOCKED'),
            $this->gatePolicy('Gate Plate Only -> Review', 'REVIEW', 'GATE_PLATE_ONLY', 5, matchResult: 'PLATE_ONLY'),
            $this->gatePolicy('Gate Face Unknown -> Review', 'REVIEW', 'GATE_FACE_UNKNOWN', 5, eventType: 'UNKNOWN_PERSON'),
            $this->gatePolicy('Gate Liveness Failed -> Review', 'REVIEW', 'GATE_LIVENESS_FAILED', 5, eventType: 'LIVENESS_FAILED'),
        ];
    }

    /**
     * D2 "configurable to REVIEW": disabled templates that send a recognised person with someone
     * else's car, or with an unread plate, to review. Priority 150 sits between the person's
     * REVIEW/DENY rules (100) and the ALLOW rules (200), so enabling one can only make a decision
     * stricter, never turn a REVIEW or DENY into an ALLOW. Enable as is for every FACE_PRIMARY
     * gate, or copy with gate_id set for one gate.
     *
     * @return list<array<string, mixed>>
     */
    public function getGateTemplatePolicies(): array
    {
        return [
            $this->gatePolicy('Gate Vehicle Mismatch -> Review', 'REVIEW', 'GATE_VEHICLE_MISMATCH', 150, matchResult: 'MISMATCH', enabled: false),
            $this->gatePolicy('Gate Plate Unknown -> Review', 'REVIEW', 'GATE_PLATE_UNKNOWN', 150, matchResult: 'UNKNOWN', eventType: 'FACE_RECOGNIZED', enabled: false),
        ];
    }

    /** @return array<string, mixed> */
    private function gatePolicy(
        string $name,
        string $decision,
        string $reasonCode,
        int $priority,
        ?string $matchResult = null,
        ?string $vehicleStatus = null,
        ?string $eventType = null,
        bool $enabled = true,
    ): array {
        return [
            'name' => $name,
            'subject_type' => null,
            'subject_status' => null,
            'site_id' => null,
            'zone_id' => null,
            'event_type' => $eventType,
            'gate_id' => null,
            'gate_mode' => 'FACE_PRIMARY',
            'match_result' => $matchResult,
            'vehicle_status' => $vehicleStatus,
            'decision' => $decision,
            'reason_code' => $reasonCode,
            'priority' => $priority,
            'enabled' => $enabled,
        ];
    }

    /** @param list<string> $statuses @return list<array<string, mixed>> */
    private function reviewPolicies(string $subjectType, array $statuses, string $label): array
    {
        return array_map(fn (string $status) => [
            'name' => "{$label} {$status} -> Review",
            'subject_type' => $subjectType,
            'subject_status' => $status,
            'site_id' => null,
            'zone_id' => null,
            'event_type' => null,
            'decision' => 'REVIEW',
            'reason_code' => "{$subjectType}_{$status}",
            'priority' => 100,
            'enabled' => true,
        ], $statuses);
    }
}
