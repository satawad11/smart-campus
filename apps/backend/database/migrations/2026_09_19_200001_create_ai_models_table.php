<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FACE-001: AI model registry (database blueprint §5, Module 03).
//
// Registry of vision and biometric AI models (detection, alignment, quality,
// embedding/recognition, liveness).
//
// Invariants:
// - Public identification by UUID only.
// - Model artifact binaries are NEVER stored in PostgreSQL; only safe relative object-storage keys.
// - Stable (code, version) unique constraint.
// - Independent of Person, StudentProfile, and User.
// - Zero pgvector biometric embeddings or access decision fields.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->enum('model_type', [
                'FACE_DETECTION',
                'FACE_ALIGNMENT',
                'FACE_QUALITY',
                'FACE_RECOGNITION',
                'FACE_EMBEDDING',
                'LIVENESS',
                'OTHER',
            ]);
            $table->string('version', 50);
            $table->string('provider', 100)->nullable();
            $table->string('artifact_path', 255)->nullable(); // Object storage key, not a binary blob
            $table->string('model_checksum', 64)->nullable(); // SHA-256 integrity checksum
            $table->unsignedInteger('embedding_dimension')->nullable();
            $table->string('distance_metric', 50)->nullable(); // e.g. COSINE, EUCLIDEAN
            $table->decimal('threshold_high', 5, 4)->nullable(); // Pilot recommended matching threshold
            $table->decimal('threshold_low', 5, 4)->nullable(); // Pilot recommended review threshold
            $table->enum('status', ['DRAFT', 'ACTIVE', 'INACTIVE', 'DEPRECATED'])->default('DRAFT');
            $table->boolean('is_default')->default(false);
            $table->json('config')->nullable(); // Non-secret hyperparameters/inference settings
            $table->json('metadata')->nullable(); // Extended metadata
            $table->timestampTz('deployed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['code', 'version']);
            $table->index(['model_type', 'status']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
