<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CAM-001: cameras (database blueprint §6). A camera belongs to exactly one
// site and zone; ai_node assignment is optional. RTSP/credential columns are
// stored encrypted-at-rest (*_encrypted, plain `text`; the application layer
// — CAM-002 — encrypts/decrypts with Crypt::encryptString(), the same
// pattern already used for users.mfa_secret_encrypted). They are hidden by
// default at the model level (see Camera::$hidden) so a raw model
// serialization never leaks them. Soft-deletable: blueprint lists
// deleted_at, and camera history (health logs, later events) must survive
// removal from active management.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cameras', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('zone_id')->constrained('zones')->restrictOnDelete();
            $table->foreignId('ai_node_id')->nullable()->constrained('ai_nodes')->nullOnDelete();
            $table->string('camera_code')->unique();
            $table->string('name');
            $table->string('camera_type')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('stream_protocol')->nullable();
            $table->text('rtsp_url_encrypted')->nullable();
            $table->text('username_encrypted')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->string('onvif_url')->nullable();
            $table->boolean('face_detection_enabled')->default(false);
            $table->boolean('face_recognition_enabled')->default(false);
            $table->boolean('liveness_enabled')->default(false);
            $table->boolean('snapshot_enabled')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE')->index();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('site_id');
            $table->index('zone_id');
            $table->index('ai_node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cameras');
    }
};
