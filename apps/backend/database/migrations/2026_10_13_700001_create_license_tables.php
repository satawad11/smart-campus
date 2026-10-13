<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COM-02 commercial licensing. Additive only: no existing table changes.
 *
 * - installation_identities: the single identity a signed license binds to.
 * - licenses: every installed license (history kept; one ACTIVE at a time).
 *   `envelope` holds the exact signed text, re-verified on every evaluation,
 *   so editing the denormalised columns grants nothing.
 * - license_revocation_lists: each imported signed list (sequence only grows).
 * - license_revocations: permanent revocations from signed revocation lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_identities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->timestampsTz();
        });

        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('license_id')->index();
            $table->string('key_id', 64);
            $table->string('edition', 64);
            $table->string('plan', 64);
            $table->string('licensee_name');
            $table->uuid('installation_uuid');
            $table->json('features');
            $table->json('limits');
            $table->timestampTz('issued_at');
            $table->timestampTz('not_before');
            $table->timestampTz('expires_at');
            $table->unsignedSmallInteger('grace_days');
            $table->text('envelope');
            $table->enum('status', ['ACTIVE', 'SUPERSEDED'])->index();
            $table->foreignId('installed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_validated_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('license_revocation_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sequence')->unique();
            $table->timestampTz('issued_at');
            $table->unsignedInteger('entry_count');
            $table->unsignedInteger('newly_revoked_count');
            $table->timestampsTz();
        });

        Schema::create('license_revocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('license_id')->unique();
            $table->unsignedInteger('list_sequence');
            $table->timestampTz('list_issued_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_revocations');
        Schema::dropIfExists('license_revocation_lists');
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('installation_identities');
    }
};
