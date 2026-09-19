<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// STD-002: complete the deferred users.person_id -> persons.id foreign key.
//
// Canonical V1 cardinality: optional one-to-one. The column (created nullable,
// indexed in AUTH-001) stays NULLABLE — a User may exist without a Person and a
// Person without a User — but is now UNIQUE, so the same non-null Person cannot
// be linked to two users (PostgreSQL nullable-unique allows many NULLs). ON
// DELETE SET NULL: a hard-deleted Person must never cascade-delete its login
// account (preserve the security record; blueprint §14). The unique index
// replaces AUTH-001's plain index on person_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['person_id']); // AUTH-001 plain index → superseded by unique
            $table->unique('person_id');
            $table->foreign('person_id')->references('id')->on('persons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
            $table->dropUnique(['person_id']);
            $table->index('person_id');
        });
    }
};
