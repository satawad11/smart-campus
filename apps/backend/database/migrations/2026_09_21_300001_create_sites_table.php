<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CAM-001: sites (database blueprint §6, system blueprint §12 hierarchy
// Site -> Zone -> Camera). Not owned by any earlier task; AUTH-009 explicitly
// deferred creating this table ("those domains do not exist yet") to the
// first domain module that needs it, which is Module 04 (Camera). Recorded
// assumption per PROJECT_RULES §18 — see CAM-001 implementation report.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('timezone')->default('Asia/Bangkok');
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
