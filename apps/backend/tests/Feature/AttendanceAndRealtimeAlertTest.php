<?php

namespace Tests\Feature;

use App\Enums\AccessDecisionOutcome;
use App\Enums\PersonType;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Events\AccessEventBroadcast;
use App\Events\NotificationCreated;
use App\Http\Resources\AccessEventResource;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Camera\AiNodeCredentialService;
use Carbon\CarbonImmutable;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Real-time verification, automatic time-in/time-out and real-time alerts:
 * - GET /reports/attendance builds each person's first/last ALLOW sighting of a day
 * - the monitor push and event list carry a usable name (display_name is often empty)
 * - an unknown person's notification is pushed on the recipient's private channel
 */
class AttendanceAndRealtimeAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(EventTypeSeeder::class);
    }

    private function userWith(array $codes, ?Site $site = null): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'ATT_'.uniqid(), 'name' => 'Viewer', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'site_id' => $site?->id]);

        return $user;
    }

    private function sighting(Person $person, Site $site, string $at, AccessDecisionOutcome $decision = AccessDecisionOutcome::ALLOW, ?Camera $camera = null): AccessEvent
    {
        $event = AccessEvent::factory()->create([
            'person_id' => $person->id,
            'site_id' => $site->id,
            'camera_id' => $camera?->id,
            'occurred_at' => CarbonImmutable::parse($at, 'Asia/Bangkok')->utc(),
        ]);
        AccessDecision::factory()->create(['event_id' => $event->id, 'person_id' => $person->id, 'decision' => $decision]);

        return $event;
    }

    public function test_attendance_lists_first_and_last_allow_sighting_per_person(): void
    {
        $user = $this->userWith(['report.view']);
        $site = Site::factory()->create();
        $gate = Camera::factory()->create(['site_id' => $site->id, 'name' => 'ประตูหน้า']);
        $back = Camera::factory()->create(['site_id' => $site->id, 'name' => 'ประตูหลัง']);

        $student = StudentProfile::factory()->create(['student_code' => '6512345']);
        $student->person->update(['first_name' => 'Nok', 'last_name' => 'Suk', 'display_name' => null]);
        $staff = StaffProfile::factory()->create(['staff_code' => 'T01']);

        $this->sighting($student->person, $site, '2026-11-16 07:42', camera: $gate);
        $this->sighting($student->person, $site, '2026-11-16 12:00', AccessDecisionOutcome::REVIEW); // not confirmed
        $this->sighting($student->person, $site, '2026-11-16 16:31', camera: $back);
        $this->sighting($staff->person, $site, '2026-11-16 08:10', camera: $gate);         // seen once
        $this->sighting($staff->person, $site, '2026-11-15 17:00');                        // other day

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/reports/attendance?date=2026-11-16')->assertOk();
        $res->assertJsonPath('meta.total', 2)->assertJsonPath('meta.timezone', 'Asia/Bangkok');

        $rows = collect($res->json('data'))->keyBy('person.code');
        $nok = $rows['6512345'];
        $this->assertSame('Nok Suk', $nok['person']['name']);
        $this->assertSame('STUDENT', $nok['person']['person_type']);
        $this->assertStringStartsWith('2026-11-16T07:42', $nok['time_in']);
        $this->assertSame('ประตูหน้า', $nok['time_in_camera']);
        $this->assertStringStartsWith('2026-11-16T16:31', $nok['time_out']);
        $this->assertSame('ประตูหลัง', $nok['time_out_camera']);
        $this->assertSame(2, $nok['sightings']);

        $this->assertNull($rows['T01']['time_out'], 'seen once: no time out yet');

        // First row is the earliest arrival.
        $this->assertSame('6512345', $res->json('data.0.person.code'));
    }

    public function test_attendance_filters_and_validation(): void
    {
        $user = $this->userWith(['report.view']);
        $site = Site::factory()->create();
        $student = StudentProfile::factory()->create(['student_code' => 'S1']);
        $staff = StaffProfile::factory()->create(['staff_code' => 'T1']);
        $this->sighting($student->person, $site, '2026-11-16 08:00');
        $this->sighting($staff->person, $site, '2026-11-16 08:05');

        $this->actingAs($user, 'web')->getJson('/api/v1/reports/attendance?date=2026-11-16&person_type=STAFF')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.person.code', 'T1');
        $this->actingAs($user, 'web')->getJson('/api/v1/reports/attendance?date=2026-11-16&keyword=s1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.person.code', 'S1');
        $this->actingAs($user, 'web')->getJson('/api/v1/reports/attendance?date=16-11-2026')->assertStatus(422);
        $this->actingAs($this->userWith(['event.view']), 'web')->getJson('/api/v1/reports/attendance')->assertStatus(403);
    }

    public function test_attendance_respects_site_scope(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $a = StudentProfile::factory()->create(['student_code' => 'A1']);
        $b = StudentProfile::factory()->create(['student_code' => 'B1']);
        $this->sighting($a->person, $siteA, '2026-11-16 08:00');
        $this->sighting($b->person, $siteB, '2026-11-16 08:00');

        $scoped = $this->userWith(['report.view'], $siteA);

        $this->actingAs($scoped, 'web')->getJson('/api/v1/reports/attendance?date=2026-11-16')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.person.code', 'A1');
    }

    public function test_monitor_push_and_event_list_carry_a_name_when_display_name_is_empty(): void
    {
        $site = Site::factory()->create();
        $student = StudentProfile::factory()->create(['student_code' => '6599999']);
        $student->person->update(['first_name' => 'Mali', 'last_name' => 'Dee', 'display_name' => null]);
        $event = $this->sighting($student->person, $site, '2026-11-16 08:00');
        $event->update(['face_score' => 0.52]);

        $payload = (new AccessEventBroadcast($event->fresh(), $event->latestDecision))->broadcastWith();
        $this->assertSame('Mali Dee', $payload['event']['person_name']);
        $this->assertSame('STUDENT', $payload['event']['person_type']);
        $this->assertSame('6599999', $payload['event']['person_code']);
        $this->assertEqualsWithDelta(0.52, $payload['event']['face_score'], 0.0001);

        $resource = (new AccessEventResource($event->fresh()))->resolve();
        $this->assertSame('Mali Dee', $resource['person']['name']);
        $this->assertSame(PersonType::STUDENT->value, $resource['person']['person_type']);
    }

    public function test_unknown_person_notification_is_pushed_to_each_recipient(): void
    {
        Event::fake([NotificationCreated::class, AccessEventBroadcast::class]);
        $watcher = $this->userWith(['monitor.view']);

        $node = AiNode::factory()->create();
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson('/internal/v1/events/recognition', [
            'event_uuid' => (string) Str::uuid(),
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'track_id' => 'track-1',
            'occurred_at' => now()->toISOString(),
            'classification' => 'UNKNOWN',
            'candidate_person_uuid' => null,
            'similarity' => null,
            'distance' => null,
            'model_code' => 'arcface-r50-v1',
            'model_version' => '1.0.0',
            'quality_score' => 0.9,
            'liveness_status' => 'REAL',
            'rejected_locally' => false,
            'reasons' => [],
        ])->assertStatus(201);

        $this->assertSame(1, Notification::where('user_id', $watcher->id)->count(), 'inbox row written');
        $inbox = Notification::where('user_id', $watcher->id)->firstOrFail();
        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $e) use ($watcher, $inbox) {
            return $e->userUuid === $watcher->uuid
                && $e->broadcastOn()[0]->name === "private-user.{$watcher->uuid}"
                && $e->broadcastAs() === 'notification.created'
                && $e->broadcastWith()['uuid'] === $inbox->uuid
                && $e->broadcastWith()['type'] === $inbox->type;
        });
    }

    public function test_user_channel_admits_only_its_owner(): void
    {
        $owner = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $other = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $channels = app(BroadcastManager::class)->driver()->getChannels();
        $callback = $channels->get('user.{userUuid}');
        $this->assertNotNull($callback);

        $this->assertTrue((bool) $callback($owner, $owner->uuid));
        $this->assertFalse((bool) $callback($other, $owner->uuid));
    }
}
