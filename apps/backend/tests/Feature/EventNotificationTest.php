<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Camera\AiNodeCredentialService;
use Database\Seeders\AccessPolicySeeder;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EVT-012 — notifications fan out from the event/review pipeline
 * (docs/09: "... -> persist decision -> commit -> broadcast -> notify").
 * Only decisions needing human attention (REVIEW/DENY/UNKNOWN) notify —
 * ALLOW is the routine outcome and stays silent.
 */
class EventNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);
        config(['event.semantic_cooldown_seconds' => 0, 'event.unknown_cooldown_seconds' => 0]);
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'EVT_'.uniqid(), 'name' => 'Monitor', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
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

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'event_uuid' => (string) Str::uuid(),
            'camera_uuid' => null,
            'ai_node_uuid' => null,
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
        ], $overrides);
    }

    public function test_an_unknown_person_event_notifies_every_monitor_view_user(): void
    {
        $watcher = $this->userWith(['monitor.view']);
        $bystander = $this->userWith(['event.view']); // no monitor.view — must not be notified

        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
        ]))->assertStatus(201);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $watcher->id,
            'type' => 'EVENT_UNKNOWN_PERSON',
        ]);
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $bystander->id,
        ]);
    }

    public function test_an_allow_decision_notifies_no_one(): void
    {
        $watcher = $this->userWith(['monitor.view']);
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['academic_status' => 'ACTIVE']);

        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'classification' => 'MATCH',
            'candidate_person_uuid' => $person->uuid,
            // A real MATCH carries its similarity; since A-18 a MATCH without one
            // (or below the approved threshold) is ingested as REVIEW.
            'similarity' => 0.95,
        ]))->assertStatus(201);

        $this->assertSame(0, Notification::where('user_id', $watcher->id)->count());
    }

    public function test_a_manual_review_that_denies_notifies_other_monitors_but_not_the_reviewer(): void
    {
        $reviewer = $this->userWith(['event.review', 'monitor.view']);
        $watcher = $this->userWith(['monitor.view']);

        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $ingest = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
        ]));
        $ingest->assertStatus(201);
        $eventUuid = $ingest->json('data.uuid');

        // Clear the ingestion-time notifications to isolate the review's own effect.
        Notification::query()->delete();

        $this->actingAs($reviewer, 'web')->postJson("/api/v1/events/{$eventUuid}/review", [
            'decision' => 'DENY',
            'reason_code' => 'IDENTITY_MISMATCH',
        ])->assertStatus(201);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $watcher->id,
            'type' => 'EVENT_DENIED',
        ]);
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $reviewer->id,
        ]);
    }

    public function test_a_manual_review_that_allows_notifies_no_one(): void
    {
        $reviewer = $this->userWith(['event.review', 'monitor.view']);
        $watcher = $this->userWith(['monitor.view']);

        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $ingest = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
        ]));
        $eventUuid = $ingest->json('data.uuid');
        Notification::query()->delete();

        $this->actingAs($reviewer, 'web')->postJson("/api/v1/events/{$eventUuid}/review", [
            'decision' => 'ALLOW',
            'reason_code' => 'OFFICER_OVERRIDE',
        ])->assertStatus(201);

        $this->assertSame(0, Notification::where('user_id', $watcher->id)->count());
    }

    public function test_notification_data_can_deep_link_back_to_the_event(): void
    {
        $watcher = $this->userWith(['monitor.view']);
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $ingest = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
        ]));
        $eventUuid = $ingest->json('data.uuid');

        $notification = Notification::where('user_id', $watcher->id)->first();
        $this->assertNotNull($notification);
        $this->assertSame($eventUuid, $notification->data['event_uuid']);
    }
}
