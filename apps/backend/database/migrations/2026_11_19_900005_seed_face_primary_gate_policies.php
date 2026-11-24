<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// GATE-006 (ACR-VEH-01 decision D2): FACE_PRIMARY Smart Gate policies for installs that already
// hold the seeded default policies. Without them a BLOCKED vehicle driven by an entitled person
// would fall through to that person's ALLOW once feature.vehicle is switched on.
//
// Fresh installs get the same rows from AccessPolicySeeder (event_types do not exist yet when
// migrations run there, so this migration does nothing). Rows are only inserted when missing:
// an admin's later edits are never overwritten. Kept self-contained on purpose — the seeder may
// change after this migration ships. See AccessPolicySeeder::getGatePolicies() for the rules.
return new class extends Migration
{
    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['Gate Vehicle Blocked -> Deny', 'DENY', 'GATE_VEHICLE_BLOCKED', 1, null, 'BLOCKED', null, true],
            ['Gate Plate Only -> Review', 'REVIEW', 'GATE_PLATE_ONLY', 5, 'PLATE_ONLY', null, null, true],
            ['Gate Face Unknown -> Review', 'REVIEW', 'GATE_FACE_UNKNOWN', 5, null, null, 'UNKNOWN_PERSON', true],
            ['Gate Liveness Failed -> Review', 'REVIEW', 'GATE_LIVENESS_FAILED', 5, null, null, 'LIVENESS_FAILED', true],
            ['Gate Vehicle Mismatch -> Review', 'REVIEW', 'GATE_VEHICLE_MISMATCH', 150, 'MISMATCH', null, null, false],
            ['Gate Plate Unknown -> Review', 'REVIEW', 'GATE_PLATE_UNKNOWN', 150, 'UNKNOWN', null, 'FACE_RECOGNIZED', false],
        ];
    }

    public function up(): void
    {
        if (! DB::table('access_policies')->where('name', 'Liveness Failed -> Review')->exists()) {
            return;
        }

        $now = now();
        foreach ($this->rows() as [$name, $decision, $reason, $priority, $matchResult, $vehicleStatus, $eventType, $enabled]) {
            if (DB::table('access_policies')->where('name', $name)->exists()) {
                continue;
            }
            DB::table('access_policies')->insert([
                'uuid' => (string) Str::uuid(),
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
                'reason_code' => $reason,
                'priority' => $priority,
                'enabled' => $enabled,
                'valid_from' => null,
                'valid_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Disable rather than delete: access_decisions may already reference these rows.
        DB::table('access_policies')
            ->whereIn('name', array_column($this->rows(), 0))
            ->update(['enabled' => false, 'updated_at' => now()]);
    }
};
