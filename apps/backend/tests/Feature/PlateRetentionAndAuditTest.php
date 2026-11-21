<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AccessEvent;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\System\RetentionCleanupService;
use App\Services\System\SystemSettingService;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VEH-013 (ACR-VEH-01, PDPA): plate text is wiped after retention.plate_reads_days, and every
 * search by plate is audited.
 */
class PlateRetentionAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(EventTypeSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    private function plateEvent(int $daysAgo, string $type = 'PLATE_RECOGNIZED'): AccessEvent
    {
        return AccessEvent::factory()->create([
            'person_id' => null,
            'event_type' => $type,
            'occurred_at' => now()->subDays($daysAgo),
            'metadata' => [
                'model_code' => 'm',
                'plate' => [
                    'raw_text' => '1กข-1234',
                    'raw_province' => 'กทม',
                    'plate_number' => '1กข1234',
                    'plate_display' => '1กข 1234',
                    'plate_province' => 'TH-10',
                    'ocr_score' => 0.97,
                    'vehicle_uuid' => 'veh-1',
                    'vehicle_status' => 'ACTIVE',
                ],
            ],
        ]);
    }

    /** @param list<string> $codes */
    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'R_'.uniqid(), 'name' => 'R', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_setting_is_seeded_with_thirty_days(): void
    {
        $this->assertSame(30, (int) app(SystemSettingService::class)->get('retention.plate_reads_days'));
    }

    public function test_old_plate_reads_are_wiped_but_the_event_row_stays(): void
    {
        $old = $this->plateEvent(45);
        $recent = $this->plateEvent(5);
        $face = AccessEvent::factory()->create(['event_type' => 'FACE_RECOGNIZED', 'occurred_at' => now()->subDays(45)]);
        $service = app(RetentionCleanupService::class);

        $this->assertSame(1, $service->redactPlateReads(dryRun: true));
        $this->assertSame('1กข1234', $old->refresh()->metadata['plate']['plate_number']);

        $this->assertSame(1, $service->redactPlateReads());

        $plate = $old->refresh()->metadata['plate'];
        foreach (['raw_text', 'raw_province', 'plate_number', 'plate_display', 'plate_province', 'vehicle_uuid'] as $field) {
            $this->assertNull($plate[$field], $field);
        }
        $this->assertNotNull($plate['redacted_at']);
        $this->assertSame(0.97, $plate['ocr_score']);
        $this->assertSame('PLATE_RECOGNIZED', $old->event_type);
        $this->assertSame('1กข1234', $recent->refresh()->metadata['plate']['plate_number']);
        $this->assertSame('m', $old->metadata['model_code']);
        $this->assertNotNull($face->refresh());

        // Idempotent: already-wiped rows are not touched again.
        $this->assertSame(0, $service->redactPlateReads());
    }

    public function test_redaction_runs_with_all_retention_policies_and_is_reported(): void
    {
        $this->plateEvent(40, 'PLATE_UNKNOWN');

        $results = app(RetentionCleanupService::class)->runAllRetentionPolicies();

        $this->assertSame(1, $results['plate_reads']);
    }

    public function test_zero_days_disables_plate_redaction(): void
    {
        app(SystemSettingService::class)->updateGroup('RETENTION', ['retention.plate_reads_days' => 0]);
        $this->plateEvent(400);

        $this->assertSame(0, app(RetentionCleanupService::class)->redactPlateReads());
    }

    public function test_wiped_plate_is_no_longer_found_by_plate_search(): void
    {
        $this->plateEvent(45);
        app(RetentionCleanupService::class)->redactPlateReads();

        $this->actingAs($this->userWith(['event.view']), 'web')
            ->getJson('/api/v1/events?plate='.urlencode('1กข1234'))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_plate_searches_are_audited_with_who_where_and_what(): void
    {
        $user = $this->userWith(['event.view', 'report.view']);

        $this->actingAs($user, 'web')->getJson('/api/v1/events?plate='.urlencode('1กข 1234'))->assertOk();
        $this->actingAs($user, 'web')->getJson('/api/v1/history/search?plate='.urlencode('9ฮฮ9999'))->assertOk();
        $this->actingAs($user, 'web')->getJson('/api/v1/events')->assertOk();

        $logs = AuditLog::where('action', 'PLATE_SEARCHED')->orderBy('id')->get();
        $this->assertCount(2, $logs, 'only searches by plate are audited');
        $this->assertSame($user->id, $logs[0]->user_id);
        $this->assertSame('vehicle', $logs[0]->module);
        $this->assertSame(['source' => 'events', 'searched' => '1กข 1234', 'plate_number' => '1กข1234'], $logs[0]->new_values);
        $this->assertSame('history', $logs[1]->new_values['source']);
    }

    public function test_vehicle_registry_plate_and_keyword_searches_are_audited(): void
    {
        config(['features.locked_kill_switches.vehicle' => null]);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.vehicle' => true]);
        $user = $this->userWith(['vehicle.view']);

        $this->actingAs($user, 'web')->getJson('/api/v1/vehicles?plate='.urlencode('1กข1234'))->assertOk();
        $this->actingAs($user, 'web')->getJson('/api/v1/vehicles?keyword=Toyota')->assertOk();

        $this->assertSame(
            ['1กข1234', 'Toyota'],
            AuditLog::where('action', 'PLATE_SEARCHED')->orderBy('id')->get()->map(fn ($l) => $l->new_values['searched'])->all(),
        );
    }
}
