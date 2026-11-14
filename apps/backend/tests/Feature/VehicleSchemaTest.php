<?php

namespace Tests\Feature;

use App\Enums\VehicleRelation;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Person;
use App\Models\Vehicle;
use App\Models\VehicleOwnershipHistory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * VEH-002 (ACR-VEH-01): vehicles + vehicle_ownership_histories schema and model rules.
 */
class VehicleSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_have_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('vehicles', [
            'id', 'uuid', 'plate_number', 'plate_province', 'vehicle_type', 'brand', 'model', 'color',
            'status', 'notes', 'created_by_user_id', 'created_at', 'updated_at', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('vehicle_ownership_histories', [
            'id', 'uuid', 'vehicle_id', 'person_id', 'relation', 'effective_from', 'effective_to',
            'reason', 'created_by_user_id', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('vehicle_ownership_histories', 'updated_at'));
    }

    public function test_vehicle_gets_public_uuid_defaults_and_casts(): void
    {
        $vehicle = Vehicle::create(['plate_number' => '1กข1234', 'plate_province' => 'BKK'])->refresh();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $vehicle->uuid);
        $this->assertSame('uuid', $vehicle->getRouteKeyName());
        $this->assertSame(VehicleType::CAR, $vehicle->vehicle_type);
        $this->assertSame(VehicleStatus::ACTIVE, $vehicle->status);
        $this->assertSame('1กข1234', $vehicle->plate_number);
    }

    public function test_live_plate_is_unique_per_province_but_soft_deleted_plate_can_be_reused(): void
    {
        $first = Vehicle::factory()->create(['plate_number' => '1กข1234', 'plate_province' => 'BKK']);

        // Same text, other province: a different plate.
        Vehicle::factory()->create(['plate_number' => '1กข1234', 'plate_province' => 'CMI']);

        $this->assertRejectedByDatabase(
            fn () => Vehicle::factory()->create(['plate_number' => '1กข1234', 'plate_province' => 'BKK']),
            'Duplicate live plate must be rejected',
        );

        $first->delete();
        $again = Vehicle::factory()->create(['plate_number' => '1กข1234', 'plate_province' => 'BKK']);

        $this->assertNotSame($first->id, $again->id);
        $this->assertSame(1, Vehicle::onlyTrashed()->count());
    }

    public function test_vehicle_can_link_several_people_and_current_scope_filters_by_time(): void
    {
        $vehicle = Vehicle::factory()->create();
        $owner = Person::factory()->create();
        $driver = Person::factory()->create();
        $former = Person::factory()->create();

        VehicleOwnershipHistory::factory()->for($vehicle)->for($owner)->create();
        VehicleOwnershipHistory::factory()->for($vehicle)->for($driver)
            ->create(['relation' => VehicleRelation::DRIVER]);
        VehicleOwnershipHistory::factory()->for($vehicle)->for($former)
            ->create(['effective_from' => now()->subYear(), 'effective_to' => now()->subMonth()]);
        VehicleOwnershipHistory::factory()->for($vehicle)->create(['effective_from' => now()->addWeek()]);

        $this->assertCount(4, $vehicle->ownershipHistories);
        $current = $vehicle->currentOwnerships()->pluck('person_id')->sort()->values()->all();
        $this->assertSame(collect([$owner->id, $driver->id])->sort()->values()->all(), $current);

        $this->assertSame(1, $owner->vehicleOwnerships()->current()->count());
        $this->assertSame(0, $former->vehicleOwnerships()->current()->count());
        $this->assertSame(1, $former->vehicleOwnerships()->current(now()->subMonths(2))->count());
    }

    public function test_history_row_can_only_be_closed_once(): void
    {
        $link = VehicleOwnershipHistory::factory()->create();

        $link->update(['effective_to' => now()]);
        $this->assertNotNull($link->refresh()->effective_to);

        $this->expectException(LogicException::class);
        $link->update(['effective_to' => now()->addDay()]);
    }

    public function test_history_row_cannot_be_rewritten(): void
    {
        $link = VehicleOwnershipHistory::factory()->create();

        $this->expectException(LogicException::class);
        $link->update(['relation' => VehicleRelation::DRIVER]);
    }

    public function test_history_row_cannot_close_before_it_starts(): void
    {
        $link = VehicleOwnershipHistory::factory()->create(['effective_from' => now()]);

        $this->expectException(LogicException::class);
        $link->update(['effective_to' => now()->subDay()]);
    }

    public function test_history_row_cannot_be_deleted_through_the_model(): void
    {
        $link = VehicleOwnershipHistory::factory()->create();

        $this->expectException(LogicException::class);
        $link->delete();
    }

    public function test_vehicle_and_person_with_history_cannot_be_hard_deleted(): void
    {
        $link = VehicleOwnershipHistory::factory()->create();

        $this->assertRejectedByDatabase(fn () => $link->vehicle->forceDelete(),
            'Hard-deleting a vehicle with history must be restricted');
        $this->assertRejectedByDatabase(fn () => $link->person->forceDelete(),
            'Hard-deleting a person with vehicle history must be restricted');

        $this->assertDatabaseHas('vehicle_ownership_histories', ['id' => $link->id]);
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
