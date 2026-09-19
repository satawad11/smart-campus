<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// STD-002: complete the deferred users.person_id -> persons.id foreign key.
//
// The column was created (nullable, indexed) in AUTH-001. It stays NULLABLE — a
// User may exist without a Person, and a Person without a User. ON DELETE SET
// NULL: a hard-deleted Person must never cascade-delete its login account
// (preserve the security record; blueprint §14 / "user may exist without a
// person"). NO unique constraint is added: the docs do not define user↔person
// cardinality, so 1:1 is NOT assumed (see report SCHEMA CLARIFICATION); a unique
// index can be added later additively if 1:1 is confirmed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('person_id')->references('id')->on('persons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
        });
    }
};
