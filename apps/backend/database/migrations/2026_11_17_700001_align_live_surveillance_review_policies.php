<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $legacyNames = ['Visitor Active Visit -> Allow', 'Parent With Active Student -> Allow'];
        if (! DB::table('access_policies')->whereIn('name', $legacyNames)->exists()) {
            // Fresh installs receive the current rules from AccessPolicySeeder.
            // Only upgrade data that was already seeded by the old pilot.
            return;
        }

        // Preserve historical decisions linked to the old rows while stopping
        // those previous visitor/parent ALLOW rules from matching new events.
        DB::table('access_policies')
            ->whereIn('name', $legacyNames)
            ->update(['enabled' => false, 'updated_at' => now()]);

        $now = now();
        foreach ([
            [
                'name' => 'Student Person Inactive -> Review',
                'subject_type' => 'STUDENT',
                'subject_status' => 'PERSON_INACTIVE',
                'reason_code' => 'PERSON_RECORD_INACTIVE',
            ],
            [
                'name' => 'Staff Person Inactive -> Review',
                'subject_type' => 'STAFF',
                'subject_status' => 'PERSON_INACTIVE',
                'reason_code' => 'PERSON_RECORD_INACTIVE',
            ],
            [
                'name' => 'Visitor Active Visit -> Review',
                'subject_type' => 'VISITOR',
                'subject_status' => 'VISIT_ACTIVE',
                'reason_code' => 'VISITOR_ACTIVE_VISIT_REVIEW',
            ],
            [
                'name' => 'Parent With Active Student -> Review',
                'subject_type' => 'PARENT',
                'subject_status' => 'ACTIVE',
                'reason_code' => 'PARENT_ACTIVE_STUDENT_REVIEW',
            ],
        ] as $rule) {
            DB::table('access_policies')->updateOrInsert(
                ['name' => $rule['name']],
                [
                    'uuid' => (string) Str::uuid(),
                    'subject_type' => $rule['subject_type'],
                    'subject_status' => $rule['subject_status'],
                    'site_id' => null,
                    'zone_id' => null,
                    'event_type' => null,
                    'decision' => 'REVIEW',
                    'reason_code' => $rule['reason_code'],
                    'priority' => 100,
                    'enabled' => true,
                    'valid_from' => null,
                    'valid_until' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('access_policies')
            ->whereIn('name', ['Visitor Active Visit -> Allow', 'Parent With Active Student -> Allow'])
            ->update(['enabled' => true, 'updated_at' => now()]);

        DB::table('access_policies')
            ->whereIn('name', [
                'Student Person Inactive -> Review',
                'Staff Person Inactive -> Review',
                'Visitor Active Visit -> Review',
                'Parent With Active Student -> Review',
            ])
            ->update(['enabled' => false, 'updated_at' => now()]);
    }
};
