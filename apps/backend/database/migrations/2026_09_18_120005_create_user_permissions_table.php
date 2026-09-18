<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AUTH-001: per-user permission overrides (database blueprint §4).
// effect is ALLOW or DENY. Composite primary key (user_id, permission_id)
// prevents duplicate override rows for the same pair.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->enum('effect', ['ALLOW', 'DENY']);
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['user_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
