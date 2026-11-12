<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ACR-INT-02: external API connections set from the web app. One row per
// connector (code RMS first). A null column means "use the value from
// .env/config". The API key is encrypted by the application (Crypt, same as
// camera credentials) and is never returned to the browser; api_key_hint is
// the non-secret key id shown in the UI (e.g. rmsk_9bcdb909_...).
// Adds integration.manage, granted to SUPER_ADMIN only on installations whose
// role matrix was already seeded.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('base_url', 255)->nullable();
            $table->text('api_key_encrypted')->nullable();
            $table->string('api_key_hint', 40)->nullable();
            $table->jsonb('settings')->nullable();
            $table->timestampTz('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        $permissionId = DB::table('permissions')->where('code', 'integration.manage')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'code' => 'integration.manage',
                'module' => 'system',
                'name' => 'Manage External Integrations',
                'description' => 'Set and test external API connections (RMS) and their API keys',
            ]);

        if (DB::table('role_permissions')->exists()) {
            $roleId = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');
            if ($roleId !== null) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_connections');

        $permissionId = DB::table('permissions')->where('code', 'integration.manage')->value('id');
        if ($permissionId !== null) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('user_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
