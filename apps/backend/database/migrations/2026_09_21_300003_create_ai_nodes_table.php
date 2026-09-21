<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CAM-001: ai_nodes (database blueprint §6). Perception-processing node;
// may serve one or more sites, so site_id is nullable (a node is not
// required to be pinned to a single site at registration time). No
// authentication/credential columns here — per-node auth is CAM-004.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_nodes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('node_code')->unique();
            $table->string('name');
            $table->string('hostname')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('api_url')->nullable();
            $table->string('hardware_type')->nullable();
            $table->string('cpu_model')->nullable();
            $table->string('gpu_name')->nullable();
            $table->unsignedInteger('gpu_memory_mb')->nullable();
            $table->unsignedSmallInteger('max_camera')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE')->index();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_nodes');
    }
};
