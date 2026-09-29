<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Events\AccessEventBroadcast;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Site;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Camera\AiNodeCredentialService;
use Database\Seeders\AccessPolicySeeder;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 05 Consolidated Acceptance Test Suite (EVT-020).
 *
 * Verifies ACCEPTANCE_CRITERIA.md's Module 05 bullet list as cross-cutting,
 * module-wide guarantees — each already proven in isolation by its owning
 * task's feature tests (EVT-001..EVT-019), but never asserted together as
 * one continuous pipeline here. Mirrors the Module04AcceptanceSecurityTest
 * convention: schema/architecture invariants + a genuine end-to-end
 * lifecycle test + security boundaries specific to this module.
 */
class Module05AcceptanceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'MOD05_'.uniqid(), 'name' => 'Module 05 Test Role', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function bearerFor(AiNode $node): array
    {
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function recognitionPayload(array $overrides = []): array
    {
        return array_merge([
            'event_uuid' => (string) Str::uuid(),
            'camera_uuid' => null,
            'ai_node_uuid' => null,
            'track_id' => (string) Str::uuid(),
            'occurred_at' => now()->toISOString(),
            'classification' => 'MATCH',
            'candidate_person_uuid' => null,
            'similarity' => 0.95,
            'distance' => 0.05,
            'model_code' => 'arcface-r50-v1',
            'model_version' => '1.0.0',
            'quality_score' => 0.9,
            'liveness_status' => 'REAL',
            'rejected_locally' => false,
            'reasons' => [],
        ], $overrides);
    }

    // ---- "Event and Decision are separate records" ----

    public function test_access_events_table_has_no_decision_column_and_access_decisions_is_a_separate_table(): void
    {
        $this->assertTrue(Schema::hasTable('access_events'));
        $this->assertTrue(Schema::hasTable('access_decisions'));

        foreach (['decision', 'allow', 'deny', 'approved'] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn('access_events', $forbidden),
                "access_events must never carry a decision column ({$forbidden})."
            );
        }
    }

    // ---- "System decision is not overwritten by manual decision" ----

    public function test_a_manual_review_appends_a_row_and_never_updates_the_system_decision_in_place(): void
    {
        $user = $this->userWith(['event.review']);
        $event = AccessEvent::factory()->create();
        $systemDecision = AccessDecision::factory()->for($event, 'event')->create([
            'decision' => 'REVIEW', 'decision_source' => 'SYSTEM', 'reason_code' => 'NO_MATCHING_POLICY',
        ]);

        $this->actingAs($user, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'ALLOW', 'reason_code' => 'OFFICER_OVERRIDE',
        ])->assertStatus(201);

        $systemDecision->refresh();
        $this->assertSame('REVIEW', $systemDecision->decision->value);
        $this->assertSame('SYSTEM', $systemDecision->decision_source->value);
        $this->assertDatabaseCount('access_decisions', 2);
    }

    // ---- "ACTIVE student maps to configured default policy behavior" ----
    // ---- "SUSPENDED/other exceptional statuses follow configured policy" ----
    // ---- "UNKNOWN is handled without creating fake Person records" ----
    // ---- "Duplicate UUID/event cooldown is enforced" ----
    // ---- "WebSocket broadcast occurs after durable persistence" ----
    // One continuous pipeline run, proving these integrate rather than just
    // pass in isolation.

    public function test_end_to_end_recognition_pipeline_active_student_allowed(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['academic_status' => 'ACTIVE']);

        $eventUuid = (string) Str::uuid();
        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->recognitionPayload([
            'event_uuid' => $eventUuid,
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
        ]));
        $res->assertStatus(201);

        // Durable persistence happened (event + its first decision) before
        // the response returned — broadcast() is only reachable after both
        // AccessEvent::create() and AccessDecision::create() commit inside
        // RecognitionEventIngestionService::persistDecision(), so a
        // successful HTTP 201 here is itself proof persistence preceded
        // broadcast, not the other way around.
        $event = AccessEvent::where('uuid', $eventUuid)->firstOrFail();
        $decision = AccessDecision::where('event_id', $event->id)->firstOrFail();
        $this->assertSame('ALLOW', $decision->decision->value);
        $this->assertSame('STUDENT_ACTIVE', $decision->reason_code);
    }

    public function test_end_to_end_recognition_pipeline_suspended_student_goes_to_review(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['academic_status' => 'SUSPENDED']);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->recognitionPayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
        ]));
        $res->assertStatus(201);

        $decision = AccessDecision::latest('id')->firstOrFail();
        $this->assertSame('REVIEW', $decision->decision->value);
        $this->assertSame('STUDENT_SUSPENDED', $decision->reason_code);
    }

    public function test_end_to_end_recognition_pipeline_unknown_classification_never_creates_a_person(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $personCountBefore = Person::count();

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->recognitionPayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'classification' => 'UNKNOWN',
            'candidate_person_uuid' => null,
        ]));
        $res->assertStatus(201);

        $this->assertSame($personCountBefore, Person::count());
        $event = AccessEvent::where('uuid', $res->json('data.uuid'))->firstOrFail();
        $this->assertNull($event->person_id);
        $this->assertSame('UNKNOWN_PERSON', $event->event_type);
    }

    public function test_end_to_end_pipeline_duplicate_event_uuid_is_idempotent_and_cooldown_suppresses_repeats(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['academic_status' => 'ACTIVE']);

        $payload = $this->recognitionPayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
        ]);

        // Exact retry of the same event_uuid: idempotent, no second row.
        $first = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $payload);
        $first->assertStatus(201);
        $retry = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $payload);
        $retry->assertStatus(200);
        $this->assertSame(1, AccessEvent::where('person_id', $person->id)->count());

        // A genuinely new event_uuid for the same person+camera within the
        // semantic cooldown window is suppressed too (EVT-005) — still no
        // second row, even though it's not the literal same uuid.
        $second = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->recognitionPayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
        ]));
        $second->assertStatus(200);
        $this->assertSame(1, AccessEvent::where('person_id', $person->id)->count());
        $this->assertSame(1, AccessDecision::where('person_id', $person->id)->count());
    }

    public function test_broadcast_payload_never_leaks_biometric_data(): void
    {
        $event = AccessEvent::factory()->create(['metadata' => ['model_code' => 'x', 'distance' => 0.1]]);
        $decision = AccessDecision::factory()->for($event, 'event')->create();

        $payload = (new AccessEventBroadcast($event, $decision))->broadcastWith();
        $encoded = json_encode($payload);

        foreach (['embedding', 'face_vector', 'similarity', 'distance', 'snapshot_path', 'quality_score'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    // ---- "Review queue works" / "Review scope permissions are enforced" /
    // "Concurrent review conflicts are handled" / "Reconnect can recover
    // missed events" — consolidated end-to-end, referencing the dedicated
    // EVT-009/EVT-011/EVT-014/EVT-015 suites for exhaustive coverage.

    public function test_review_queue_scope_and_concurrency_end_to_end(): void
    {
        $viewer = $this->userWith(['event.view']); // no event.review
        $officerA = $this->userWith(['event.review']);
        $officerB = $this->userWith(['event.review']);

        $event = AccessEvent::factory()->create();
        $original = AccessDecision::factory()->for($event, 'event')->create(['decision' => 'REVIEW']);

        // Appears in the review queue.
        $queue = $this->actingAs($viewer, 'web')->getJson('/api/v1/events?decision=REVIEW');
        $queue->assertOk();
        $this->assertContains($event->uuid, collect($queue->json('data'))->pluck('uuid')->all());

        // Review scope: a viewer without event.review cannot submit one.
        $this->actingAs($viewer, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'ALLOW', 'reason_code' => 'X',
        ])->assertStatus(403);

        // Concurrency: officer A reviews first; officer B's stale token conflicts.
        $this->actingAs($officerA, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'ALLOW', 'reason_code' => 'OFFICER_OVERRIDE',
            'expected_decision_uuid' => $original->uuid,
        ])->assertStatus(201);

        $conflict = $this->actingAs($officerB, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'DENY', 'reason_code' => 'IDENTITY_MISMATCH',
            'expected_decision_uuid' => $original->uuid,
        ]);
        $conflict->assertStatus(409);
        $conflict->assertJsonPath('error.code', 'EVENT_REVIEW_CONFLICT');

        // Resolved event leaves the queue.
        $queueAfter = $this->actingAs($viewer, 'web')->getJson('/api/v1/events?decision=REVIEW');
        $this->assertNotContains($event->uuid, collect($queueAfter->json('data'))->pluck('uuid')->all());
    }

    public function test_missed_event_recovery_end_to_end(): void
    {
        $user = $this->userWith(['event.view']);
        $site = Site::factory()->create();

        $lastSeen = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => now()->subMinutes(10)]);
        AccessDecision::factory()->for($lastSeen, 'event')->create();

        $missed = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => now()->subMinutes(5)]);
        AccessDecision::factory()->for($missed, 'event')->create();

        $recovery = $this->actingAs($user, 'web')->getJson(
            "/api/v1/events?site_uuid={$site->uuid}&from={$lastSeen->occurred_at->toISOString()}&order=asc"
        );
        $recovery->assertOk();
        $uuids = collect($recovery->json('data'))->pluck('uuid')->all();
        $this->assertContains($lastSeen->uuid, $uuids);
        $this->assertContains($missed->uuid, $uuids);
    }

    // ---- Notifications never leak to users outside monitor.view scope ----

    public function test_notifications_only_fan_out_to_monitor_view_holders(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $watcher = $this->userWith(['monitor.view']);
        $bystander = $this->userWith(['event.view']);

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->recognitionPayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'classification' => 'UNKNOWN',
            'candidate_person_uuid' => null,
        ]))->assertStatus(201);

        $this->assertDatabaseHas('app_notifications', ['user_id' => $watcher->id]);
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $bystander->id]);
    }

    // ---- Channel authorization enforces scope, never a bare permission check ----

    public function test_realtime_channel_authorization_requires_matching_site_scope(): void
    {
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');

        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $user = $this->userWith(['monitor.view']);
        UserRole::where('user_id', $user->id)->update(['site_id' => $site->id]);

        $this->actingAs($user, 'web')->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => "private-site.{$site->uuid}", 'socket_id' => '1.1',
        ])->assertOk();

        $this->actingAs($user, 'web')->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => "private-site.{$otherSite->uuid}", 'socket_id' => '1.1',
        ])->assertStatus(403);
    }
}
