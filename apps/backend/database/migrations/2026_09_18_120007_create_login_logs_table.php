<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AUTH-001: login attempt history (database blueprint §4).
//
// Security/historical record. user_id is nullable and uses ON DELETE SET NULL
// so the log survives if a user row is ever removed (do not destroy login
// history — PROJECT_RULES §7 / blueprint §14). `username` is always retained.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('username')->index();
            $table->boolean('success');
            $table->string('failure_reason')->nullable();
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->timestampTz('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_logs');
    }
};
