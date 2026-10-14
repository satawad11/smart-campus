<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COM-03: what an AI node reports it is running (model code/version, for
 * the model compatibility check) and its disk usage. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_nodes', function (Blueprint $table) {
            $table->string('model_code', 64)->nullable();
            $table->string('model_version', 64)->nullable();
        });

        Schema::table('ai_node_health_logs', function (Blueprint $table) {
            $table->float('disk_usage')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_node_health_logs', function (Blueprint $table) {
            $table->dropColumn('disk_usage');
        });

        Schema::table('ai_nodes', function (Blueprint $table) {
            $table->dropColumn(['model_code', 'model_version']);
        });
    }
};
