<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\Camera;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Site;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Zone;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EVT-008 — event history API (GET /events, GET /events/{uuid}).
 * Read-only: never accepts or produces a decision.
 */
class EventHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(EventTypeSeeder::class);
    }

    private function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'EVT_'.uniqid(), 'name' => 'Event Viewer', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/events')->assertStatus(401);
    }

    public function test_requires_event_view_permission(): void
    {
        $user = $this->userWith([]);
        $this->actingAs($user, 'web')->getJson('/api/v1/events')->assertStatus(403);
    }

    public function test_lists_events_with_current_decision_summary(): void
    {
        $user = $this->userWith(['event.view']);
        $event = AccessEvent::factory()->create();
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'ALLOW']);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/events');

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.uuid', $event->uuid);
        $res->assertJsonPath('data.0.current_decision.decision', 'ALLOW');
    }

    public function test_current_decision_reflects_the_latest_appended_decision(): void
    {
        $user = $this->userWith(['event.view']);
        $event = AccessEvent::factory()->create();
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'REVIEW']);
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'ALLOW']); // officer override, later

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/events/{$event->uuid}");

        $res->assertOk();
        $res->assertJsonPath('data.current_decision.decision', 'ALLOW');
        $res->assertJsonCount(2, 'data.decisions');
    }

    public function test_show_returns_full_decision_history_in_order(): void
    {
        $user = $this->userWith(['event.view']);
        $event = AccessEvent::factory()->create();
        $first = AccessDecision::factory()->for($event, 'event')->create(['decision' => 'REVIEW']);
        $second = AccessDecision::factory()->for($event, 'event')->create(['decision' => 'DENY']);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/events/{$event->uuid}");

        $res->assertOk();
        $uuids = collect($res->json('data.decisions'))->pluck('uuid')->all();
        $this->assertSame([$first->uuid, $second->uuid], $uuids);
    }

    public function test_show_exposes_why_the_node_rejected_the_face(): void
    {
        $user = $this->userWith(['event.view']);
        $event = AccessEvent::factory()->create([
            'event_type' => 'UNKNOWN_PERSON',
            'face_score' => null,
            'metadata' => [
                'classification' => 'UNKNOWN',
                'rejected_locally' => true,
                'reasons' => ['BAD_POSE', 'IMAGE_BLUR'],
                'distance' => null,
            ],
        ]);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/events/{$event->uuid}");

        $res->assertOk()
            ->assertJsonPath('data.recognition.classification', 'UNKNOWN')
            ->assertJsonPath('data.recognition.rejected_locally', true)
            ->assertJsonPath('data.recognition.reasons', ['BAD_POSE', 'IMAGE_BLUR'])
            // Diagnostic block only — never leaks other metadata.
            ->assertJsonMissingPath('data.recognition.distance')
            ->assertJsonMissingPath('data.metadata');
    }

    public function test_recognition_summary_is_empty_for_events_without_metadata(): void
    {
        $user = $this->userWith(['event.view']);
        $event = AccessEvent::factory()->create(['metadata' => null]);

        $this->actingAs($user, 'web')->getJson("/api/v1/events/{$event->uuid}")
            ->assertOk()
            ->assertJsonPath('data.recognition.classification', null)
            ->assertJsonPath('data.recognition.rejected_locally', null)
            ->assertJsonPath('data.recognition.reasons', []);
    }

    public function test_node_supplied_reasons_are_bounded_before_reaching_the_browser(): void
    {
        $user = $this->userWith(['event.view']);
        $event = AccessEvent::factory()->create([
            'metadata' => [
                'classification' => 'UNKNOWN',
                'rejected_locally' => 'yes', // not a boolean: dropped
                'reasons' => array_merge([str_repeat('X', 500), ['nested'], 42], array_fill(0, 20, 'BAD_POSE')),
            ],
        ]);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/events/{$event->uuid}");

        $res->assertOk()->assertJsonPath('data.recognition.rejected_locally', null);
        $reasons = $res->json('data.recognition.reasons');
        $this->assertCount(10, $reasons);
        $this->assertSame(120, mb_strlen($reasons[0]));
        $this->assertContainsOnly('string', $reasons);
    }

    public function test_filters_by_person_uuid(): void
    {
        $user = $this->userWith(['event.view']);
        $personA = Person::factory()->create();
        $personB = Person::factory()->create();
        AccessEvent::factory()->create(['person_id' => $personA->id]);
        AccessEvent::factory()->create(['person_id' => $personB->id]);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/events?person_uuid={$personA->uuid}");

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.person.uuid', $personA->uuid);
    }

    public function test_filters_by_student_code(): void
    {
        $user = $this->userWith(['event.view']);
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['student_code' => 'STU-001']);
        AccessEvent::factory()->create(['person_id' => $person->id]);
        AccessEvent::factory()->create(['person_id' => Person::factory()->create()->id]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/events?student_code=STU-001');

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
    }

    public function test_filters_by_academic_status(): void
    {
        $user = $this->userWith(['event.view']);
        $suspended = Person::factory()->create();
        StudentProfile::factory()->for($suspended)->create(['academic_status' => 'SUSPENDED']);
        $active = Person::factory()->create();
        StudentProfile::factory()->for($active)->create(['academic_status' => 'ACTIVE']);

        AccessEvent::factory()->create(['person_id' => $suspended->id]);
        AccessEvent::factory()->create(['person_id' => $active->id]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/events?academic_status=SUSPENDED');

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.person.uuid', $suspended->uuid);
    }

    public function test_filters_by_site_zone_camera(): void
    {
        $user = $this->userWith(['event.view']);
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $camera = Camera::factory()->create(['site_id' => $site->id, 'zone_id' => $zone->id]);

        AccessEvent::factory()->create(['site_id' => $site->id, 'zone_id' => $zone->id, 'camera_id' => $camera->id]);
        AccessEvent::factory()->create(); // different site entirely

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/events?site_uuid={$site->uuid}");
        $res->assertOk();
        $res->assertJsonCount(1, 'data');

        $res2 = $this->actingAs($user, 'web')->getJson("/api/v1/events?camera_uuid={$camera->uuid}");
        $res2->assertOk();
        $res2->assertJsonCount(1, 'data');
    }

    public function test_filters_by_event_type(): void
    {
        $user = $this->userWith(['event.view']);
        AccessEvent::factory()->create(['event_type' => 'FACE_RECOGNIZED']);
        AccessEvent::factory()->create(['event_type' => 'UNKNOWN_PERSON']);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/events?event_type=UNKNOWN_PERSON');

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.event_type', 'UNKNOWN_PERSON');
    }

    public function test_filters_by_decision_using_the_latest_not_the_original(): void
    {
        $user = $this->userWith(['event.view']);

        $overridden = AccessEvent::factory()->create();
        AccessDecision::factory()->for($overridden, 'event')->create(['decision' => 'REVIEW']);
        AccessDecision::factory()->for($overridden, 'event')->create(['decision' => 'ALLOW']);

        $stillReview = AccessEvent::factory()->create();
        AccessDecision::factory()->for($stillReview, 'event')->create(['decision' => 'REVIEW']);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/events?decision=REVIEW');

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.uuid', $stillReview->uuid);
    }

    public function test_filters_by_date_range(): void
    {
        $user = $this->userWith(['event.view']);
        $old = AccessEvent::factory()->create(['occurred_at' => now()->subDays(10)]);
        $recent = AccessEvent::factory()->create(['occurred_at' => now()]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/events?from='.now()->subDay()->toISOString());

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.uuid', $recent->uuid);
    }

    public function test_response_never_exposes_raw_embedding_or_snapshot_bytes(): void
    {
        $user = $this->userWith(['event.view']);
        AccessEvent::factory()->create();

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/events');
        $res->assertOk();
        $data = $res->json('data.0');
        foreach (['embedding', 'snapshot_bytes', 'metadata'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
    }

    /**
     * On PostgreSQL, person_uuid/site_uuid/zone_uuid/camera_uuid are native uuid
     * columns: `exists` runs a raw SQL comparison, which throws "invalid input
     * syntax for type uuid" (500) for a non-uuid string unless the validator's
     * `uuid` rule rejects the format first (bails before `exists` runs). SQLite's
     * text-typed uuid column never surfaces this, so the assertion only holds on
     * pgsql — see ReviewEventRequest for the same fix applied to expected_decision_uuid.
     */
    public function test_malformed_uuid_filters_return_422_not_500(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestIncomplete('invalid uuid syntax only throws a raw SQL error on PostgreSQL');
        }

        $user = $this->userWith(['event.view']);

        foreach (['person_uuid', 'site_uuid', 'zone_uuid', 'camera_uuid'] as $param) {
            $res = $this->actingAs($user, 'web')->getJson("/api/v1/events?{$param}=not-a-uuid");
            $res->assertStatus(422);
            $res->assertJsonPath('error.code', 'VALIDATION_ERROR');
            $this->assertArrayHasKey($param, $res->json('error.fields'), "expected a validation error for {$param}");
        }
    }

    public function test_show_unknown_uuid_returns_404(): void
    {
        $user = $this->userWith(['event.view']);
        $this->actingAs($user, 'web')->getJson('/api/v1/events/00000000-0000-0000-0000-000000000000')->assertStatus(404);
    }
}
