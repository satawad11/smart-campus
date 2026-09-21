<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CAM-004: per-node machine credentials, replacing CAM-003's temporary
// shared internal.token secret. token_hash stores sha256(plaintext) only —
// the plaintext token is shown to the caller exactly once at issuance and
// never persisted or recoverable. restrictOnDelete preserves credential
// history (issuance/revocation is a security-relevant historical fact).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_node_credentials', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ai_node_id')->constrained('ai_nodes')->restrictOnDelete();
            $table->string('token_hash')->unique();
            $table->enum('status', ['ACTIVE', 'REVOKED'])->default('ACTIVE')->index();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['ai_node_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_node_credentials');
    }
};
