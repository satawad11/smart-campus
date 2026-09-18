<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AUTH-001: user_roles assignment (database blueprint §4).
//
// site_id / zone_id are scope columns for entities owned by later modules
// (sites/zones — Module 04). They are kept nullable + indexed so real FKs can
// be added later without a destructive migration; NO foreign key is created
// now because the referenced tables do not exist yet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->unsignedBigInteger('site_id')->nullable()->index(); // deferred FK -> sites
            $table->unsignedBigInteger('zone_id')->nullable()->index(); // deferred FK -> zones
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
