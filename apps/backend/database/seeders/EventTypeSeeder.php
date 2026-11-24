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
            // VEH-006 (ACR-VEH-01): licence-plate results from AI nodes. A plate event records
            // what was read; it never grants access on its own (face stays primary, D2).
            [
                'code' => 'PLATE_RECOGNIZED',
                'name_th' => 'อ่านทะเบียนรถที่ลงทะเบียนไว้',
                'name_en' => 'Registered Plate Recognized',
                'category' => 'PLATE',
                'severity' => 'INFO',
                'active' => true,
            ],
            [
                'code' => 'PLATE_UNKNOWN',
                'name_th' => 'ทะเบียนรถที่ไม่ได้ลงทะเบียน',
                'name_en' => 'Unregistered Plate',
                'category' => 'PLATE',
                'severity' => 'WARNING',
                'active' => true,
            ],
            [
                'code' => 'PLATE_LOW_CONFIDENCE',
                'name_th' => 'อ่านทะเบียนรถได้ไม่ชัดเจน',
                'name_en' => 'Plate Read With Low Confidence',
                'category' => 'PLATE',
                'severity' => 'INFO',
                'active' => true,
            ],
        ];
    }
}
