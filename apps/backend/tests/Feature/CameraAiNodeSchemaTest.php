<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\HealthStatus;
use App\Enums\LocationStatus;
use App\Models\AiNode;
use App\Models\AiNodeHealthLog;
use App\Models\Camera;
use App\Models\CameraHealthLog;
use App\Models\Site;
use App\Models\Zone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CAM-001 — Camera / AI Node / health schema tests.
 *
 * Schema-only task: migrations, models, relationships. No CRUD/service/API
 * yet (CAM-002+). Active connection: SQLite locally, real PostgreSQL in
 * docker verification.
 */
class CameraAiNodeSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    // ================= Tables / columns =================

    public function test_sites_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('sites'));
        $this->assertTrue(Schema::hasColumns('sites', [
            'id', 'uuid', 'code', 'name', 'address', 'timezone', 'status', 'created_at', 'updated_at',
        ]));
    }

    public function test_zones_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('zones'));
        $this->assertTrue(Schema::hasColumns('zones', [
            'id', 'uuid', 'site_id', 'parent_zone_id', 'code', 'name', 'zone_type', 'status', 'created_at', 'updated_at',
        ]));
    }

    public function test_ai_nodes_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('ai_nodes'));
        $this->assertTrue(Schema::hasColumns('ai_nodes', [
            'id', 'uuid', 'site_id', 'node_code', 'name', 'hostname', 'ip_address', 'api_url',
            'hardware_type', 'cpu_model', 'gpu_name', 'gpu_memory_mb', 'max_camera', 'status',
            'last_seen_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_cameras_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('cameras'));
        $this->assertTrue(Schema::hasColumns('cameras', [
            'id', 'uuid', 'site_id', 'zone_id', 'ai_node_id', 'camera_code', 'name', 'camera_type',
            'ip_address', 'stream_protocol', 'rtsp_url_encrypted', 'username_encrypted',
            'password_encrypted', 'onvif_url', 'face_detection_enabled', 'face_recognition_enabled',
            'liveness_enabled', 'snapshot_enabled', 'status', 'last_seen_at', 'created_at',
            'updated_at', 'deleted_at',
        ]));
    }

    public function test_health_log_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('camera_health_logs'));
        $this->assertTrue(Schema::hasColumns('camera_health_logs', [
            'id', 'camera_id', 'status', 'fps', 'latency_ms', 'dropped_frames', 'resolution',
            'brightness_score', 'blur_score', 'reconnect_count', 'recorded_at',
        ]));

        $this->assertTrue(Schema::hasTable('ai_node_health_logs'));
        $this->assertTrue(Schema::hasColumns('ai_node_health_logs', [
            'id', 'ai_node_id', 'cpu_usage', 'ram_usage', 'gpu_usage', 'gpu_memory_usage',
            'temperature', 'active_camera_count', 'queue_depth', 'status', 'recorded_at',
        ]));
    }

    // ================= Security-relevant absence checks =================

    public function test_no_plaintext_credential_or_biometric_columns(): void
    {
        // Only the *_encrypted columns may exist; no plaintext sibling columns.
        foreach (['rtsp_url', 'username', 'password', 'rtsp_password', 'secret'] as $col) {
            $this->assertFalse(Schema::hasColumn('cameras', $col), "cameras must not have plaintext {$col}");
        }
        foreach (['embedding', 'face_template', 'access_decision', 'allow', 'deny'] as $col) {
            $this->assertFalse(Schema::hasColumn('cameras', $col), "cameras must not have {$col}");
            $this->assertFalse(Schema::hasColumn('ai_nodes', $col), "ai_nodes must not have {$col}");
        }
    }

    public function test_ai_nodes_have_no_authentication_secret_columns(): void
    {
        // Per-node authentication is CAM-004 — no credential columns yet.
        foreach (['token', 'secret', 'api_key', 'password', 'credential'] as $col) {
            $this->assertFalse(Schema::hasColumn('ai_nodes', $col), "ai_nodes must not have {$col} (CAM-004 scope)");
        }
    }

    // ================= UUID identity =================

    public function test_uuid_unique_and_generated_on_identity_tables(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create();

        foreach ([$site, $zone, $node, $camera] as $model) {
            $this->assertNotEmpty($model->uuid);
            $this->assertSame('uuid', $model->getRouteKeyName());
        }
    }

    public function test_health_log_tables_have_no_public_uuid(): void
    {
        // Internal telemetry rows are not independently addressable resources.
        $this->assertFalse(Schema::hasColumn('camera_health_logs', 'uuid'));
        $this->assertFalse(Schema::hasColumn('ai_node_health_logs', 'uuid'));
    }

    // ================= Uniqueness =================

    public function test_site_code_unique(): void
    {
        Site::factory()->create(['code' => 'MAIN']);
        $this->expectException(QueryException::class);
        Site::factory()->create(['code' => 'MAIN']);
    }

    public function test_zone_code_unique_within_site_not_globally(): void
    {
        $site = Site::factory()->create();
        Zone::factory()->for($site)->create(['code' => 'GATE1']);

        $this->expectException(QueryException::class);
        Zone::factory()->for($site)->create(['code' => 'GATE1']);
    }

    public function test_zone_code_may_repeat_across_different_sites(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        Zone::factory()->for($siteA)->create(['code' => 'GATE1']);

        $zoneB = Zone::factory()->for($siteB)->create(['code' => 'GATE1']);
        $this->assertSame('GATE1', $zoneB->code);
    }

    public function test_ai_node_code_unique(): void
    {
        AiNode::factory()->create(['node_code' => 'NODE-A']);
        $this->expectException(QueryException::class);
        AiNode::factory()->create(['node_code' => 'NODE-A']);
    }

    public function test_camera_code_unique(): void
    {
        Camera::factory()->create(['camera_code' => 'CAM-A']);
        $this->expectException(QueryException::class);
        Camera::factory()->create(['camera_code' => 'CAM-A']);
    }

    // ================= Relationships =================

    public function test_relationships_and_casts(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $node = AiNode::factory()->create(['site_id' => $site->id]);
        $camera = Camera::factory()->create([
            'site_id' => $site->id,
            'zone_id' => $zone->id,
            'ai_node_id' => $node->id,
        ]);

        $this->assertTrue($camera->site->is($site));
        $this->assertTrue($camera->zone->is($zone));
        $this->assertTrue($camera->aiNode->is($node));
        $this->assertTrue($zone->site->is($site));
        $this->assertTrue($site->zones->contains($zone));
        $this->assertTrue($site->cameras->contains($camera));
        $this->assertTrue($node->cameras->contains($camera));

        $this->assertInstanceOf(LocationStatus::class, $site->status);
        $this->assertInstanceOf(LocationStatus::class, $zone->status);
        $this->assertInstanceOf(DeviceStatus::class, $node->status);
        $this->assertInstanceOf(DeviceStatus::class, $camera->status);
    }

    public function test_zone_self_referential_hierarchy(): void
    {
        $site = Site::factory()->create();
        $parent = Zone::factory()->for($site)->create();
        $child = Zone::factory()->for($site)->create(['parent_zone_id' => $parent->id]);

        $this->assertTrue($child->parentZone->is($parent));
        $this->assertTrue($parent->childZones->contains($child));
    }

    public function test_health_log_relationships_and_casts(): void
    {
        $camera = Camera::factory()->create();
        $node = AiNode::factory()->create();

        $cameraLog = CameraHealthLog::factory()->for($camera)->create(['status' => 'DEGRADED']);
        $nodeLog = AiNodeHealthLog::factory()->for($node, 'aiNode')->create(['status' => 'OFFLINE']);

        $this->assertTrue($cameraLog->camera->is($camera));
        $this->assertTrue($nodeLog->aiNode->is($node));
        $this->assertInstanceOf(HealthStatus::class, $cameraLog->status);
        $this->assertInstanceOf(HealthStatus::class, $nodeLog->status);
        $this->assertTrue($camera->healthLogs->contains($cameraLog));
        $this->assertTrue($node->healthLogs->contains($nodeLog));
    }

    // ================= FK delete behavior =================

    public function test_site_with_zones_cannot_be_hard_deleted(): void
    {
        $site = Site::factory()->create();
        Zone::factory()->for($site)->create();

        $this->expectException(QueryException::class);
        $site->delete();
    }

    public function test_deleting_parent_zone_nulls_child_parent_reference(): void
    {
        $site = Site::factory()->create();
        $parent = Zone::factory()->for($site)->create();
        $child = Zone::factory()->for($site)->create(['parent_zone_id' => $parent->id]);

        $parent->delete();

        $this->assertNull($child->fresh()->parent_zone_id);
    }

    public function test_deleting_site_referenced_by_ai_node_sets_null(): void
    {
        $site = Site::factory()->create();
        $node = AiNode::factory()->create(['site_id' => $site->id]);

        // A node's site is optional per blueprint (a node may serve multiple
        // sites); no cameras/zones reference this site so it can be removed.
        $site->delete();

        $this->assertNull($node->fresh()->site_id);
    }

    public function test_camera_with_site_or_zone_still_assigned_cannot_be_hard_deleted(): void
    {
        $camera = Camera::factory()->create();

        $this->expectException(QueryException::class);
        $camera->site->delete();
    }

    public function test_deleting_ai_node_nulls_camera_assignment(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $node->delete();

        $this->assertNull($camera->fresh()->ai_node_id);
    }

    public function test_camera_health_log_survives_and_restricts_hard_delete_of_camera(): void
    {
        $camera = Camera::factory()->create();
        CameraHealthLog::factory()->for($camera)->create();

        $this->expectException(QueryException::class);
        DB::table('cameras')->where('id', $camera->id)->delete();
    }

    // ================= Soft delete (camera only) =================

    public function test_camera_soft_delete_preserves_row(): void
    {
        $camera = Camera::factory()->create();
        $camera->delete();

        $this->assertSoftDeleted('cameras', ['id' => $camera->id]);
        $this->assertDatabaseHas('cameras', ['id' => $camera->id]);
    }

    public function test_site_zone_ai_node_have_no_soft_delete(): void
    {
        foreach (['sites', 'zones', 'ai_nodes', 'camera_health_logs', 'ai_node_health_logs'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'deleted_at'), "{$table} must not have deleted_at");
        }
    }

    // ================= Serialization privacy =================

    public function test_camera_credentials_hidden_from_array_and_json(): void
    {
        $camera = Camera::factory()->create([
            'rtsp_url_encrypted' => 'encrypted-rtsp-placeholder',
            'username_encrypted' => 'encrypted-username-placeholder',
            'password_encrypted' => 'encrypted-password-placeholder',
        ]);

        $array = $camera->toArray();
        $json = $camera->toJson();

        foreach (['rtsp_url_encrypted', 'username_encrypted', 'password_encrypted'] as $field) {
            $this->assertArrayNotHasKey($field, $array, "{$field} must be hidden from array serialization");
            $this->assertStringNotContainsString('encrypted-', $json, "{$field} raw value must never appear in JSON");
        }
    }

    public function test_camera_fillable_never_exposes_internal_numeric_ids_as_public_contract(): void
    {
        // uuid, not id, is the route key / public identity for every device/location model.
        foreach ([Site::factory()->create(), Zone::factory()->create(), AiNode::factory()->create(), Camera::factory()->create()] as $model) {
            $this->assertSame('uuid', $model->getRouteKeyName());
        }
    }

    // ================= Enum CHECK behavior (PostgreSQL only) =================

    public function test_invalid_device_status_rejected(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestIncomplete('status CHECK rejection is only enforced on PostgreSQL');
        }

        // raw() (not make()) bypasses the Eloquent enum cast so the invalid
        // value actually reaches the database CHECK constraint, rather than
        // being rejected earlier by PHP's own backed-enum ValueError.
        $attributes = Camera::factory()->raw(['status' => 'BOGUS']);
        $attributes['uuid'] = (string) Str::uuid();
        unset($attributes['deleted_at']);

        $this->expectException(QueryException::class);
        DB::table('cameras')->insert($attributes);
    }

    public function test_invalid_health_status_rejected(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestIncomplete('status CHECK rejection is only enforced on PostgreSQL');
        }

        $camera = Camera::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('camera_health_logs')->insert([
            'camera_id' => $camera->id,
            'status' => 'BOGUS',
            'recorded_at' => now(),
        ]);
    }

    // ================= Scope ownership (CAM-001 minimum: FK linkage only) =================

    public function test_camera_scope_resolves_through_zone_and_site(): void
    {
        $siteA = Site::factory()->create();
        $zoneA = Zone::factory()->for($siteA)->create();
        $cameraA = Camera::factory()->create(['site_id' => $siteA->id, 'zone_id' => $zoneA->id]);

        $siteB = Site::factory()->create();
        $zoneB = Zone::factory()->for($siteB)->create();
        $cameraB = Camera::factory()->create(['site_id' => $siteB->id, 'zone_id' => $zoneB->id]);

        $this->assertTrue($cameraA->site->is($siteA));
        $this->assertFalse($cameraA->site->is($siteB));
        $this->assertTrue($cameraB->zone->site->is($siteB));
    }
}
