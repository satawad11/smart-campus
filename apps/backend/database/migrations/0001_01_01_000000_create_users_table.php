<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // AUTH-001: spec-compliant users table (database blueprint §4).
        // Internal PK is BIGINT; `uuid` is the external/public identifier (§2).
        // Timestamps are TIMESTAMPTZ (UTC) per the blueprint.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // person_id: FK to persons is deferred to Module 02 (Person). Kept
            // nullable + indexed so the FK can be added later without a
            // destructive migration.
            $table->unsignedBigInteger('person_id')->nullable()->index();
            $table->string('username')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->enum('status', ['ACTIVE', 'LOCKED', 'DISABLED', 'EXPIRED'])
                ->default('ACTIVE')
                ->index();
            $table->boolean('mfa_enabled')->default(false);
            // Encrypted MFA material only; no MFA logic/secrets in AUTH-001.
            $table->text('mfa_secret_encrypted')->nullable();
            $table->unsignedInteger('failed_login_count')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
            // Framework "remember me" support column (Authenticatable).
            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
