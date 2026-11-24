<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// VEH-002 (ACR-VEH-01, approved 2026-11-12): vehicles and vehicle_ownership_histories.
//
// A vehicle is a registry record (plate + description). Who it belongs to lives in the
// append-only ownership history, linked to persons (the master identity), never to users or
// students. A vehicle may be linked to several persons (owner, regular drivers, authorised).
// Plate text is stored already normalised; the normalisation rules and the province code list
// are VEH-003. Nothing here is read by the access pipeline until the ALPR tasks wire it in.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Normalised Thai plate text without spaces or dashes, e.g. "1กข1234" (VEH-003).
            $table->string('plate_number', 16);
            // Province code from the fixed Thai province list (VEH-003).
            $table->string('plate_province', 8);
            $table->enum('vehicle_type', ['CAR', 'MOTORCYCLE', 'PICKUP', 'VAN', 'TRUCK', 'BUS', 'OTHER'])->default('CAR');
            $table->string('brand', 64)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('color', 32)->nullable();
            // BLOCKED = known vehicle that must be denied at a gate (ACR-VEH-01 D2).
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'BLOCKED'])->default('ACTIVE')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('plate_number');
        });

        // One live record per plate; a soft-deleted vehicle keeps its plate for history and
        // does not block re-registering the same plate. Partial indexes work on PostgreSQL
        // and SQLite (test suite).
        DB::statement('CREATE UNIQUE INDEX vehicles_plate_unique_live ON vehicles (plate_number, plate_province) WHERE deleted_at IS NULL');

        Schema::create('vehicle_ownership_histories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // RESTRICT: a vehicle or person with history cannot be hard-deleted from under it.
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            $table->enum('relation', ['OWNER', 'DRIVER', 'AUTHORIZED']);
            // Business time: when the link applies. Ending a link sets effective_to once;
            // nothing else on a row ever changes (enforced in VehicleOwnershipHistory).
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // System time: when the row was recorded. No updated_at (append-oriented).
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['vehicle_id', 'effective_from']);
            $table->index(['person_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_ownership_histories');
        DB::statement('DROP INDEX IF EXISTS vehicles_plate_unique_live');
        Schema::dropIfExists('vehicles');
    }
};
