<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FACE-008: add pgvector embedding column to face_profiles table (database blueprint §5, Module 03).
//
// Invariants:
// - Vector dimension: 512 (standard canonical face embedding vector length).
// - Distance operator: vector_cosine_ops (<=>) for unit normalized embeddings.
// - HNSW indexing on PostgreSQL for accelerated approximate / exact candidate retrieval.
// - Raw vectors never exposed to generic resource serialization.
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector;');
            DB::statement('ALTER TABLE face_profiles ADD COLUMN IF NOT EXISTS embedding vector(512) NULL;');
            DB::statement('CREATE INDEX IF NOT EXISTS face_profiles_embedding_hnsw_idx ON face_profiles USING hnsw (embedding vector_cosine_ops);');
        } else {
            Schema::table('face_profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('face_profiles', 'embedding')) {
                    $table->text('embedding')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS face_profiles_embedding_hnsw_idx;');
            DB::statement('ALTER TABLE face_profiles DROP COLUMN IF EXISTS embedding;');
        } else {
            Schema::table('face_profiles', function (Blueprint $table) {
                if (Schema::hasColumn('face_profiles', 'embedding')) {
                    $table->dropColumn('embedding');
                }
            });
        }
    }
};
