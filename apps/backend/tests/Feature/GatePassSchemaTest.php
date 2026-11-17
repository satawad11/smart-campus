<?php

namespace Tests\Feature;

use App\Enums\GateMatchResult;
use App\Models\AccessEvent;
use App\Models\Gate;
use App\Models\GatePass;
use App\Models\Person;
use App\Models\Site;
use App\Models\Vehicle;
use App\Services\System\RetentionCleanupService;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * GATE-003 (ACR-VEH-01 Phase 5): gate_passes links events of one pass and never modifies them.
 */
class GatePassSchemaTest extends TestCase
{
    use RefreshDatabase;

    private Gate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EventTypeSeeder::class);
        $this->gate = Gate::factory()->create();
    }

    private function event(string $type, array $overrides = []): AccessEvent
    {
        return AccessEvent::factory()->create(array_merge([
            'site_id' => $this->gate->site_id,
            'zone_id' => $this->gate->zone_id,
            'event_type' => $type,
            'occurred_at' => now()->subSeconds(30),
        ], $overrides));
    }

    private function pass(GateMatchResult $result, ?AccessEvent $face, ?AccessEvent $plate, array $overrides = []): GatePass
    {
        return GatePass::create(array_merge([
            'gate_id' => $this->gate->id,
            'face_event_id' => $face?->id,
            'plate_event_id' => $plate?->id,
            'match_result' => $result,
            'occurred_at' => ($face ?? $plate)->occurred_at,
        ], $overrides));
    }

    public function test_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('gate_passes', [
            'id', 'uuid', 'gate_id', 'face_event_id', 'plate_event_id', 'person_id', 'vehicle_id',
            'match_result', 'occurred_at', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('gate_passes', 'updated_at'));
        // No decision or actuation column: the decision stays in access_decisions (D5).
        $this->assertFalse(Schema::hasColumn('gate_passes', 'decision'));
    }

    public function test_matched_pass_links_both_events_and_leaves_them_untouched(): void
    {
        $person = Person::factory()->create();
        $vehicle = Vehicle::factory()->create();
        $face = $this->event('FACE_RECOGNIZED', ['person_id' => $person->id]);
        $plate = $this->event('PLATE_RECOGNIZED', ['metadata' => ['plate' => ['plate_number' => '1กข1234']]]);
        $before = [$face->fresh()->toArray(), $plate->fresh()->toArray()];

        $pass = $this->pass(GateMatchResult::MATCHED, $face, $plate, [
            'person_id' => $person->id,
            'vehicle_id' => $vehicle->id,
        ])->refresh();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $pass->uuid);
        $this->assertSame(GateMatchResult::MATCHED, $pass->match_result);
        $this->assertTrue($pass->faceEvent->is($face));
        $this->assertTrue($pass->plateEvent->is($plate));
        $this->assertTrue($pass->person->is($person));
        $this->assertTrue($pass->vehicle->is($vehicle));
        $this->assertTrue($this->gate->passes()->first()->is($pass));
        $this->assertSame($before, [$face->fresh()->toArray(), $plate->fresh()->toArray()]);
    }

    public function test_single_event_passes(): void
    {
        $this->pass(GateMatchResult::FACE_ONLY, $this->event('UNKNOWN_PERSON'), null);
        $this->pass(GateMatchResult::PLATE_ONLY, null, $this->event('PLATE_UNKNOWN'));
        $this->pass(GateMatchResult::UNKNOWN, $this->event('UNKNOWN_PERSON'), $this->event('PLATE_RECOGNIZED'));

        $this->assertSame(3, GatePass::count());
    }

    public function test_match_result_must_fit_the_events(): void
    {
        $face = $this->event('FACE_RECOGNIZED');
        $plate = $this->event('PLATE_RECOGNIZED');

        foreach ([
            [GateMatchResult::FACE_ONLY, $face, $plate],
            [GateMatchResult::PLATE_ONLY, $face, $plate],
            [GateMatchResult::UNKNOWN, $face, null],
            [GateMatchResult::UNKNOWN, null, $plate],
            [GateMatchResult::FACE_ONLY, null, $plate],
        ] as [$result, $f, $p]) {
            try {
                $this->pass($result, $f, $p);
                $this->fail("{$result->value} must be rejected for these events");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, GatePass::count());
    }

    public function test_matched_and_mismatch_need_person_and_vehicle(): void
    {
        $this->expectException(LogicException::class);
        $this->pass(GateMatchResult::MISMATCH, $this->event('FACE_RECOGNIZED'), $this->event('PLATE_RECOGNIZED'), [
            'person_id' => Person::factory()->create()->id,
        ]);
    }

    public function test_event_slots_must_hold_the_right_event_category(): void
    {
        $this->expectException(LogicException::class);
        // A plate event in the face slot.
        $this->pass(GateMatchResult::FACE_ONLY, $this->event('PLATE_RECOGNIZED'), null);
    }

    public function test_events_must_be_in_the_gate_site(): void
    {
        $foreign = $this->event('FACE_RECOGNIZED', ['site_id' => Site::factory()->create()->id, 'zone_id' => null]);

        $this->expectException(LogicException::class);
        $this->pass(GateMatchResult::FACE_ONLY, $foreign, null);
    }

    public function test_an_event_belongs_to_at_most_one_pass(): void
    {
        $face = $this->event('FACE_RECOGNIZED');
        $this->pass(GateMatchResult::FACE_ONLY, $face, null);

        $this->assertRejectedByDatabase(
            fn () => $this->pass(GateMatchResult::FACE_ONLY, $face, null),
            'The same face event must not be linked to two passes',
        );
    }

    public function test_pass_is_immutable_and_never_deleted_through_the_model(): void
    {
        $pass = GatePass::factory()->create();

        try {
            $pass->update(['match_result' => GateMatchResult::UNKNOWN]);
            $this->fail('A saved pass must not change');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        $pass->delete();
    }

    public function test_linked_event_and_gate_cannot_be_hard_deleted_from_under_a_pass(): void
    {
        $pass = GatePass::factory()->create();

        $this->assertRejectedByDatabase(
            fn () => DB::table('access_events')->where('id', $pass->face_event_id)->delete(),
            'A linked event must not be deleted while its pass exists',
        );
        $this->assertRejectedByDatabase(
            fn () => DB::table('gates')->where('id', $pass->gate_id)->delete(),
            'A gate with passes must not be hard-deleted',
        );
    }

    public function test_retention_removes_passes_with_their_events_and_drops_redacted_vehicle_links(): void
    {
        $this->seed(SystemSettingSeeder::class);
        $person = Person::factory()->create();
        $vehicle = Vehicle::factory()->create();

        // 45 days old: plate read redacted (30 days), event kept (90 days).
        $redacted = $this->pass(
            GateMatchResult::MATCHED,
            $this->event('FACE_RECOGNIZED', ['person_id' => $person->id, 'occurred_at' => now()->subDays(45)]),
            $this->event('PLATE_RECOGNIZED', ['occurred_at' => now()->subDays(45), 'metadata' => ['plate' => ['plate_number' => '1กข1234']]]),
            ['person_id' => $person->id, 'vehicle_id' => $vehicle->id],
        );
        // 120 days old: events deleted, so the pass goes too.
        $expired = $this->pass(GateMatchResult::FACE_ONLY, $this->event('FACE_RECOGNIZED', ['occurred_at' => now()->subDays(120)]), null);
        // Recent: untouched.
        $recent = $this->pass(GateMatchResult::PLATE_ONLY, null, $this->event('PLATE_RECOGNIZED'), ['vehicle_id' => $vehicle->id]);

        app(RetentionCleanupService::class)->runAllRetentionPolicies();

        $this->assertNull($redacted->fresh()->vehicle_id);
        $this->assertSame($person->id, $redacted->fresh()->person_id);
        $this->assertNull(GatePass::find($expired->id));
        $this->assertSame($vehicle->id, $recent->fresh()->vehicle_id);
    }

    private function assertRejectedByDatabase(callable $statement, string $message): void
    {
        try {
            DB::transaction($statement);
            $this->fail($message);
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
