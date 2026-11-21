<?php

namespace Tests\Feature;

use App\Enums\GateCameraRole;
use App\Enums\GateDirection;
use App\Enums\GateMode;
use App\Enums\LocationStatus;
use App\Models\Camera;
use App\Models\Gate;
use App\Models\GateCamera;
use App\Models\Zone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * GATE-001 (ACR-VEH-01 Phase 5): gates + gate_cameras schema and model rules.
 */
class GateSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_have_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('gates', [
            'id', 'uuid', 'site_id', 'zone_id', 'code', 'name', 'direction', 'correlation_window_seconds',
            'mode', 'status', 'notes', 'created_by_user_id', 'created_at', 'updated_at', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('gate_cameras', [
            'id', 'gate_id', 'camera_id', 'role', 'created_at', 'updated_at',
        ]));
    }

    public function test_gate_gets_public_uuid_and_face_primary_defaults(): void
    {
        $zone = Zone::factory()->create();
        $gate = Gate::create([
            'site_id' => $zone->site_id,
            'zone_id' => $zone->id,
            'code' => 'MAIN_IN',
            'name' => 'ประตูหน้า',
        ])->refresh();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $gate->uuid);
        $this->assertSame('uuid', $gate->getRouteKeyName());
        $this->assertSame(GateDirection::BOTH, $gate->direction);
        $this->assertSame(GateMode::FACE_PRIMARY, $gate->mode);
        $this->assertSame(LocationStatus::ACTIVE, $gate->status);
        $this->assertSame(10, $gate->correlation_window_seconds);
    }

    public function test_gate_zone_must_belong_to_the_gate_site(): void
    {
        $gate = Gate::factory()->create();
        $otherZone = Zone::factory()->create();

        $this->expectException(LogicException::class);
        $gate->update(['zone_id' => $otherZone->id]);
    }

    public function test_live_gate_code_is_unique_per_site_but_soft_deleted_code_can_be_reused(): void
    {
        $first = Gate::factory()->create(['code' => 'MAIN']);
        // Same code at another site is fine.
        Gate::factory()->create(['code' => 'MAIN']);

        $this->assertRejectedByDatabase(
            fn () => Gate::create(['site_id' => $first->site_id, 'zone_id' => $first->zone_id, 'code' => 'MAIN', 'name' => 'x']),
            'Duplicate live gate code within a site must be rejected',
        );

        $first->delete();
        $again = Gate::create(['site_id' => $first->site_id, 'zone_id' => $first->zone_id, 'code' => 'MAIN', 'name' => 'x']);

        $this->assertNotSame($first->id, $again->id);
    }

    public function test_gate_links_face_and_plate_cameras_with_roles(): void
    {
        $gate = Gate::factory()->create();
        $face = Camera::factory()->create(['site_id' => $gate->site_id, 'zone_id' => $gate->zone_id]);
        $plate = Camera::factory()->create(['site_id' => $gate->site_id, 'zone_id' => $gate->zone_id]);

        GateCamera::create(['gate_id' => $gate->id, 'camera_id' => $face->id, 'role' => GateCameraRole::FACE]);
        GateCamera::create(['gate_id' => $gate->id, 'camera_id' => $plate->id, 'role' => GateCameraRole::PLATE]);

        $roles = $gate->cameras()->orderBy('cameras.id')->get()->map(fn ($c) => $c->pivot->role)->all();
        $this->assertSame([GateCameraRole::FACE, GateCameraRole::PLATE], $roles);
        $this->assertSame($gate->id, $plate->gateCamera->gate_id);

        $this->assertTrue(GateCameraRole::BOTH->readsFaces() && GateCameraRole::BOTH->readsPlates());
        $this->assertFalse(GateCameraRole::FACE->readsPlates());
        $this->assertFalse(GateCameraRole::PLATE->readsFaces());
    }

    public function test_a_camera_serves_at_most_one_gate(): void
    {
        $link = GateCamera::factory()->create();
        $camera = $link->camera;
        $other = Gate::factory()->create(['site_id' => $camera->site_id, 'zone_id' => $camera->zone_id, 'code' => 'OTHER']);

        $this->assertRejectedByDatabase(
            fn () => GateCamera::create(['gate_id' => $other->id, 'camera_id' => $camera->id, 'role' => GateCameraRole::FACE]),
            'A camera already on a gate must not join a second gate',
        );
    }

    public function test_gate_camera_must_be_in_the_gate_site(): void
    {
        $gate = Gate::factory()->create();
        $foreign = Camera::factory()->create();

        $this->expectException(LogicException::class);
        GateCamera::create(['gate_id' => $gate->id, 'camera_id' => $foreign->id, 'role' => GateCameraRole::FACE]);
    }

    public function test_camera_and_zone_with_a_gate_cannot_be_hard_deleted_and_gate_removal_drops_links(): void
    {
        $link = GateCamera::factory()->create();

        $this->assertRejectedByDatabase(fn () => $link->camera->forceDelete(),
            'Hard-deleting a camera assigned to a gate must be restricted');
        $this->assertRejectedByDatabase(fn () => $link->gate->zone->delete(),
            'Hard-deleting a zone with a gate must be restricted');

        $link->gate->forceDelete();
        $this->assertDatabaseMissing('gate_cameras', ['id' => $link->id]);
    }

    /**
     * Run a statement that must fail inside a savepoint, so PostgreSQL does not abort the
     * test transaction and later statements in the same test still run.
     */
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
