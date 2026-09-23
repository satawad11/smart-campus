<?php

namespace Database\Seeders;

use App\Models\EventType;
use Illuminate\Database\Seeder;

/**
 * Seeds the canonical event-type codes (EVT-002). access_policies.event_type
 * foreign-keys to event_types.code, so the default policies AccessPolicySeeder
 * creates cannot exist until these rows do — this seeder is a prerequisite
 * for that one and runs first in DatabaseSeeder.
 *
 * Idempotent: updateOrCreate keyed on `code`, same convention as
 * PermissionSeeder (AUTH-003) / AccessPolicySeeder.
 *
 * Scoped to exactly the codes EVT-002's default policies reference — not a
 * speculative full catalog. Later tasks (EVT-003's ingestion service,
 * further module work) add more codes as they're actually produced.
 */
class EventTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->getCanonicalEventTypes() as $type) {
            EventType::updateOrCreate(
                ['code' => $type['code']],
                $type,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCanonicalEventTypes(): array
    {
        return [
            [
                'code' => 'FACE_RECOGNIZED',
                'name_th' => 'ตรวจพบใบหน้าที่รู้จัก',
                'name_en' => 'Face Recognized',
                'category' => 'RECOGNITION',
                'severity' => 'INFO',
                'active' => true,
            ],
            [
                'code' => 'UNKNOWN_PERSON',
                'name_th' => 'ตรวจพบบุคคลที่ไม่รู้จัก',
                'name_en' => 'Unknown Person Detected',
                'category' => 'RECOGNITION',
                'severity' => 'WARNING',
                'active' => true,
            ],
            [
                'code' => 'LIVENESS_FAILED',
                'name_th' => 'ตรวจสอบความมีชีวิตล้มเหลว',
                'name_en' => 'Liveness Check Failed',
                'category' => 'RECOGNITION',
                'severity' => 'WARNING',
                'active' => true,
            ],
        ];
    }
}
