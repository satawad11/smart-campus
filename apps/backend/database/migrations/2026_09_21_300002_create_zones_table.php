<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CAM-001: zones (database blueprint §6). Belongs to a site; may nest under a
// parent zone (self-referential hierarchy). zone_type has no canonical value
// set defined in any spec, so it is stored as a free-form string rather than
// an invented enum (PROJECT_RULES §18 — never invent major product
// requirements). code is unique within its owning site.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Preserve site structure: a site with zones cannot be hard-deleted.
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('parent_zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('zone_type')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE')->index();
            $table->timestampsTz();

            $table->unique(['site_id', 'code']);
            $table->index('parent_zone_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
