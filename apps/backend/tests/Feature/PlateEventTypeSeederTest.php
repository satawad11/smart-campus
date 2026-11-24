<?php

namespace Tests\Feature;

use App\Models\AccessPolicy;
use App\Models\EventType;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VEH-006 (ACR-VEH-01): plate event types are seeded, idempotent, listed in the contract, and
 * not used by any default access policy (plates never grant access on their own).
 */
class PlateEventTypeSeederTest extends TestCase
{
    use RefreshDatabase;

    private const PLATE_CODES = ['PLATE_RECOGNIZED', 'PLATE_UNKNOWN', 'PLATE_LOW_CONFIDENCE'];

    public function test_plate_event_types_are_seeded_once_with_thai_names(): void
    {
        $this->seed(EventTypeSeeder::class);
        $this->seed(EventTypeSeeder::class);

        foreach (self::PLATE_CODES as $code) {
            $type = EventType::where('code', $code)->sole();
            $this->assertSame('PLATE', $type->category);
            $this->assertTrue((bool) $type->active);
            $this->assertMatchesRegularExpression('/\p{Thai}/u', $type->name_th);
        }
        $this->assertSame('WARNING', EventType::where('code', 'PLATE_UNKNOWN')->value('severity'));
    }

    public function test_existing_event_types_are_unchanged(): void
    {
        $this->seed(EventTypeSeeder::class);

        foreach (['FACE_RECOGNIZED', 'UNKNOWN_PERSON', 'LIVENESS_FAILED'] as $code) {
            $this->assertSame('RECOGNITION', EventType::where('code', $code)->value('category'));
        }
    }

    public function test_contract_lists_the_plate_event_types(): void
    {
        // Plain-text read: the backend has no direct YAML dependency.
        $yaml = (string) file_get_contents(dirname(base_path(), 2).'/contracts/event-types.yaml');
        [$current, $future] = explode('future_event_types:', $yaml, 2);

        foreach (self::PLATE_CODES as $code) {
            $this->assertMatchesRegularExpression("/^\s*-\s*{$code}\s*$/m", $current);
            $this->assertDoesNotMatchRegularExpression("/^\s*-\s*{$code}\s*$/m", $future);
        }
    }

    public function test_no_default_policy_uses_plate_events(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, AccessPolicy::whereIn('event_type', self::PLATE_CODES)->count());
    }
}
