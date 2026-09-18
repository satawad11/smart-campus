<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AUTH-001: application session tracking (database blueprint §4).
// Distinct from Laravel's framework `sessions` store table. `uuid` is the
// external identifier. Sessions follow the user lifecycle (cascade on delete).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_id')->index();
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->string('device_name')->nullable();
            $table->timestampTz('login_at');
            $table->timestampTz('last_activity_at');
            $table->timestampTz('logout_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
