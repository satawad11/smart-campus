<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AccessEvent;
use App\Models\Camera;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VEH-012 (ACR-VEH-01): plate events in the events API, history search and range reports.
 */
class PlateEventViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(EventTypeSeeder::class);
    }

    /** @param list<string> $codes */
    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'PLT_'.uniqid(), 'name' => 'Viewer', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function plateEvent(string $type, ?string $plate, array $extra = [], array $attrs = []): AccessEvent
    {
        return AccessEvent::factory()->create(array_merge([
            'person_id' => null,
            'event_type' => $type,
            'occurred_at' => now()->subMinutes(5),
            'metadata' => [
                'plate' => array_merge([
                    'plate_number' => $plate,
                    'plate_display' => $plate === null ? null : '1กข 1234',
                    'plate_province' => 'TH-10',
                    'raw_text' => '1กข-1234',
                    'ocr_score' => 0.97,
                    'vehicle_uuid' => null,
                    'vehicle_status' => null,
                ], $extra),
            ],
        ], $attrs));
    }

    public function test_events_api_exposes_plate_block_only_on_plate_events(): void
    {
        $plate = $this->plateEvent('PLATE_RECOGNIZED', '1กข1234', ['vehicle_uuid' => 'veh-1', 'vehicle_status' => 'BLOCKED']);
        $face = AccessEvent::factory()->create(['event_type' => 'FACE_RECOGNIZED']);
        $user = $this->userWith(['event.view']);

        $show = $this->actingAs($user, 'web')->getJson("/api/v1/events/{$plate->uuid}")->assertOk();
        $show->assertJsonPath('data.plate.plate_display', '1กข 1234')
            ->assertJsonPath('data.plate.plate_province_name', 'กรุงเทพมหานคร')
            ->assertJsonPath('data.plate.vehicle_status', 'BLOCKED')
            ->assertJsonPath('data.person', null)
            ->assertJsonPath('data.decisions', []);

        $this->actingAs($user, 'web')->getJson("/api/v1/events/{$face->uuid}")
            ->assertOk()->assertJsonPath('data.plate', null);
    }

    public function test_events_and_history_filter_by_normalised_plate(): void
    {
        $match = $this->plateEvent('PLATE_UNKNOWN', '1กข1234');
        $this->plateEvent('PLATE_UNKNOWN', '9ฮฮ9999');
        AccessEvent::factory()->create(['event_type' => 'FACE_RECOGNIZED']);

        $events = $this->actingAs($this->userWith(['event.view']), 'web')
            ->getJson('/api/v1/events?plate='.urlencode('๑กข-๑๒๓๔'))->assertOk();
        $events->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $match->uuid);

        $history = $this->actingAs($this->userWith(['report.view']), 'web')
            ->getJson('/api/v1/history/search?plate='.urlencode('1กข 1234'))->assertOk();
        $history->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $match->uuid);

        $this->actingAs($this->userWith(['event.view']), 'web')
            ->getJson('/api/v1/events?plate=garbage')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_plate_event_cannot_be_reviewed_into_a_decision(): void
    {
        $plate = $this->plateEvent('PLATE_UNKNOWN', '1กข1234');

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($this->userWith(['event.view', 'event.review']), 'web')
            ->postJson("/api/v1/events/{$plate->uuid}/review", ['decision' => 'ALLOW', 'reason_code' => 'OFFICER_OVERRIDE'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PLATE_EVENT_NOT_REVIEWABLE');

        $this->assertSame(0, $plate->decisions()->count());
    }

    public function test_daily_report_counts_plate_reads_without_touching_face_totals(): void
    {
        $camera = Camera::factory()->create();
        $site = ['site_id' => $camera->site_id, 'camera_id' => $camera->id];
        $this->plateEvent('PLATE_RECOGNIZED', '1กข1234', ['vehicle_status' => 'BLOCKED'], $site);
        $this->plateEvent('PLATE_RECOGNIZED', '2กข2222', ['vehicle_status' => 'ACTIVE'], $site);
        $this->plateEvent('PLATE_UNKNOWN', '3กข3333', [], $site);
        $this->plateEvent('PLATE_LOW_CONFIDENCE', null, [], $site);

        $res = $this->actingAs($this->userWith(['report.view']), 'web')
            ->getJson('/api/v1/reports/daily?date='.now()->toDateString())->assertOk();

        $res->assertJsonPath('data.plates.recognized', 2)
            ->assertJsonPath('data.plates.unknown', 1)
            ->assertJsonPath('data.plates.low_confidence', 1)
            ->assertJsonPath('data.plates.blocked_vehicle_reads', 1)
            ->assertJsonPath('data.totals.recognized', 0);
    }
}
