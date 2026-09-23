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
 * STUDENT+ACTIVE is the lowest-priority (200) of the student policies: it
 * is the "nothing exceptional" fallback among them.
 */
class AccessPolicySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->getDefaultPolicies() as $policy) {
            AccessPolicy::updateOrCreate(
                ['name' => $policy['name']],
                $policy,
            );
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
        ];
    }
}
