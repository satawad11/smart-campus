<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// EVT-001: event_types (database blueprint §7). A reference/lookup table of
// canonical event codes (e.g. FACE_RECOGNIZED, UNKNOWN_PERSON,
// LIVENESS_FAILED) — access_events.event_type stores the code string
// directly (not a numeric id) and foreign-keys to event_types.code, so the
// column is self-describing in raw event rows without a join. category and
// severity have no canonical value set defined anywhere in the specs
// (unlike Decision's explicit ALLOW/REVIEW/DENY/UNKNOWN or Decision
// source's SYSTEM/POLICY/OFFICER), so they are free-form strings rather
// than invented enums, matching Zone.zone_type's precedent.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_th');
            $table->string('name_en');
            $table->string('category')->nullable();
            $table->string('severity')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_types');
    }
};
