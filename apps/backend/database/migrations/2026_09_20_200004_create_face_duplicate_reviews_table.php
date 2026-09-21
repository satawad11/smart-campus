<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FACE-011: face_duplicate_reviews table.
 *
 * Tracks potential duplicate face enrollments requiring human review.
 *
 * Invariants:
 * - Public identity via UUID.
 * - Idempotency enforced via UNIQUE(source_face_profile_id, candidate_face_profile_id, recognition_model_id).
 * - Review decisions (PENDING, CONFIRMED_DUPLICATE, NOT_DUPLICATE, DISMISSED) are audited.
 * - Confirmation does NOT automatically merge Person or mutate profiles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_duplicate_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('source_face_profile_id')->constrained('face_profiles')->cascadeOnDelete();
            $table->foreignId('candidate_face_profile_id')->constrained('face_profiles')->cascadeOnDelete();
            $table->foreignId('recognition_model_id')->constrained('ai_models')->cascadeOnDelete();
            $table->decimal('distance', 6, 5);
            $table->decimal('similarity', 6, 5);
            $table->enum('review_status', [
                'PENDING',
                'CONFIRMED_DUPLICATE',
                'NOT_DUPLICATE',
                'DISMISSED',
            ])->default('PENDING');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();
            $table->timestampsTz();

            $table->unique(
                ['source_face_profile_id', 'candidate_face_profile_id', 'recognition_model_id'],
                'face_dup_reviews_source_cand_model_unique'
            );
            $table->index('review_status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_duplicate_reviews');
    }
};
