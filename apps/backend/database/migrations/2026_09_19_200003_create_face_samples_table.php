<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FACE-002: face_samples table (database blueprint §5, Module 03).
//
// Represents raw/processed biometric enrollment samples associated with a FaceProfile.
//
// Invariants:
// - Samples belong to FaceProfile (face_profile_id).
// - Object storage path stored as relative string key, NEVER image BLOB/binary.
// - Public identification by UUID only.
// - Zero vector embedding columns.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_samples', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('face_profile_id')->constrained('face_profiles')->cascadeOnDelete();
            $table->string('image_path', 255); // Object storage key (e.g. biometrics/faces/{profile_uuid}/{sample_uuid}.jpg)
            $table->enum('pose', ['FRONT', 'LEFT_SLIGHT', 'RIGHT_SLIGHT', 'OTHER'])->default('FRONT');
            $table->enum('source', ['UPLOAD', 'CAMERA', 'CAPTURE'])->default('UPLOAD');
            $table->decimal('quality_score', 5, 4)->nullable();
            $table->decimal('blur_score', 5, 4)->nullable();
            $table->decimal('brightness_score', 5, 4)->nullable();
            $table->decimal('face_size_score', 5, 4)->nullable();
            $table->decimal('yaw', 6, 2)->nullable();
            $table->decimal('pitch', 6, 2)->nullable();
            $table->decimal('roll', 6, 2)->nullable();
            $table->boolean('accepted')->default(false);
            $table->string('rejection_reason', 255)->nullable();
            $table->string('checksum', 64)->nullable(); // SHA-256 integrity checksum
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('mime_type', 50)->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->timestampsTz();

            $table->index('face_profile_id');
            $table->index('accepted');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_samples');
    }
};
