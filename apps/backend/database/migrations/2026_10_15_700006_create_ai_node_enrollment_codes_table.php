<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// COM-03e: one-time enrollment codes. An administrator issues a short-lived
// code for a node; the node exchanges it once for its machine credential.
// code_hash stores sha256(normalised code) only; the plaintext is shown to
// the administrator once. Rows are kept as enrollment history.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_node_enrollment_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ai_node_id')->constrained('ai_nodes')->restrictOnDelete();
            $table->string('code_hash')->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->string('used_from_ip', 45)->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->timestampsTz();

            $table->index(['ai_node_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_node_enrollment_codes');
    }
};
