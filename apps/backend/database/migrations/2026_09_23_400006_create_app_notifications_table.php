<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// EVT-012: app_notifications (docs/02-api-contract.md §17: GET
// /notifications, PATCH /notifications/{uuid}/read, POST
// /notifications/read-all — the table name differs from the route path
// deliberately: `notifications` is Laravel's own conventional table for
// the framework's built-in Notifiable::notifications() morph relation
// (notifiable_type/notifiable_id columns), which User already inherits via
// the Notifiable trait. Reusing that name here with an incompatible
// user_id-based schema would silently break that relation the moment
// anything calls ->notify() with the database channel. `app_notifications`
// avoids the collision entirely; only the storage name differs, not the
// public API.
//
// Personal inbox rows, one per recipient — a decision needing attention
// fans out to every user currently holding monitor.view, each getting
// their own row so read state is per-recipient. `data` carries just enough
// to deep-link (event_uuid, decision, reason_code) without duplicating the
// full event. No updated_at: read_at is the only mutable field, updated
// directly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
