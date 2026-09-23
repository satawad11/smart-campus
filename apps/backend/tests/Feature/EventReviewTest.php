<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EVT-010 — manual review append-only decisions.
 * docs/09: "Officer review adds a new decision. It never overwrites system
 * decision." review() must only ever INSERT, never UPDATE/DELETE a row.
 */
class EventReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(EventTypeSeeder::class);
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'EVT_'.uniqid(), 'name' => 'Reviewer', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_requires_authentication(): void
    {
        $event = AccessEvent::factory()->create();
        $this->postJson("/api/v1/events/{$event->uuid}/review", ['decision' => 'ALLOW', 'reason_code' => 'OFFICER_OVERRIDE'])
            ->assertStatus(401);
    }

    public function test_event_view_alone_is_not_enough_to_review(): void
    {
        $user = $this->userWith(['event.view']);
        $event = AccessEvent::factory()->create();

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/events/{$event->uuid}/review", ['decision' => 'ALLOW', 'reason_code' => 'OFFICER_OVERRIDE'])
            ->assertStatus(403);
    }

    public function test_a_review_appends_a_new_decision_and_never_edits_the_original(): void
    {
        $user = $this->userWith(['event.review']);
        $event = AccessEvent::factory()->create();
        $original = AccessDecision::factory()->for($event, 'event')->create([
            'decision' => 'REVIEW',
            'decision_source' => 'SYSTEM',
            'reason_code' => 'NO_MATCHING_POLICY',
        ]);

        $res = $this->actingAs($user, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'ALLOW',
            'reason_code' => 'OFFICER_OVERRIDE',
            'reason_text' => 'Verified in person, valid staff badge shown.',
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.decision', 'ALLOW');
        $res->assertJsonPath('data.decision_source', 'OFFICER');
        $res->assertJsonPath('data.reason_code', 'OFFICER_OVERRIDE');

        $this->assertDatabaseCount('access_decisions', 2);

        $original->refresh();
        $this->assertSame('REVIEW', $original->decision->value);
        $this->assertSame('SYSTEM', $original->decision_source->value);
        $this->assertSame('NO_MATCHING_POLICY', $original->reason_code);
    }

    public function test_the_reviewing_user_is_recorded_as_decided_by(): void
    {
        $user = $this->userWith(['event.review']);
        $event = AccessEvent::factory()->create();

        $res = $this->actingAs($user, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'DENY',
            'reason_code' => 'IDENTITY_MISMATCH',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('access_decisions', [
            'event_id' => $event->id,
            'decision' => 'DENY',
            'decision_source' => 'OFFICER',
            'decided_by_user_id' => $user->id,
        ]);
    }

    public function test_a_review_carries_the_events_person_not_a_client_supplied_one(): void
    {
        $user = $this->userWith(['event.review']);
        $person = Person::factory()->create();
        $event = AccessEvent::factory()->create(['person_id' => $person->id]);

        $this->actingAs($user, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'ALLOW',
            'reason_code' => 'OFFICER_OVERRIDE',
            'person_id' => 999999, // must be ignored — not a permitted field
        ])->assertStatus(201);

        $this->assertDatabaseHas('access_decisions', [
            'event_id' => $event->id,
            'person_id' => $person->id,
        ]);
    }

    public function test_a_review_never_carries_an_access_policy_id(): void
    {
        $user = $this->userWith(['event.review']);
        $event = AccessEvent::factory()->create();

        $this->actingAs($user, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'ALLOW',
            'reason_code' => 'OFFICER_OVERRIDE',
        ])->assertStatus(201);

        $this->assertDatabaseHas('access_decisions', [
            'event_id' => $event->id,
            'decision_source' => 'OFFICER',
            'access_policy_id' => null,
        ]);
    }

    public function test_decision_must_be_a_human_verdict_not_unknown(): void
    {
        $user = $this->userWith(['event.review']);
        $event = AccessEvent::factory()->create();

        $this->actingAs($user, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'UNKNOWN',
            'reason_code' => 'OFFICER_OVERRIDE',
        ])->assertStatus(422);
    }

    public function test_reason_code_is_required(): void
    {
        $user = $this->userWith(['event.review']);
        $event = AccessEvent::factory()->create();

        $this->actingAs($user, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'ALLOW',
        ])->assertStatus(422);
    }

    public function test_review_of_unknown_event_uuid_returns_404(): void
    {
        $user = $this->userWith(['event.review']);

        $this->actingAs($user, 'web')->postJson('/api/v1/events/00000000-0000-0000-0000-000000000000/review', [
            'decision' => 'ALLOW',
            'reason_code' => 'OFFICER_OVERRIDE',
        ])->assertStatus(404);
    }

    public function test_review_writes_an_audit_log_entry(): void
    {
        $user = $this->userWith(['event.review']);
        $event = AccessEvent::factory()->create();

        $this->actingAs($user, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'DENY',
            'reason_code' => 'IDENTITY_MISMATCH',
        ])->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'EVENT_REVIEWED',
            'user_id' => $user->id,
            'entity_type' => 'ACCESS_EVENT',
            'entity_id' => $event->uuid,
        ]);
    }

    public function test_the_events_current_decision_reflects_the_review_immediately(): void
    {
        $user = $this->userWith(['event.review', 'event.view']);
        $event = AccessEvent::factory()->create();
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'REVIEW']);

        $this->actingAs($user, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'ALLOW',
            'reason_code' => 'OFFICER_OVERRIDE',
        ])->assertStatus(201);

        $show = $this->actingAs($user, 'web')->getJson("/api/v1/events/{$event->uuid}");
        $show->assertJsonPath('data.current_decision.decision', 'ALLOW');
        $show->assertJsonCount(2, 'data.decisions');
    }

    public function test_reviewing_can_escalate_a_resolved_event_back_into_the_queue(): void
    {
        $user = $this->userWith(['event.review', 'event.view']);
        $event = AccessEvent::factory()->create();
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'ALLOW']);

        $this->actingAs($user, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'REVIEW',
            'reason_code' => 'NEEDS_SUPERVISOR',
        ])->assertStatus(201);

        $queue = $this->actingAs($user, 'web')->getJson('/api/v1/events?decision=REVIEW');
        $queue->assertJsonCount(1, 'data');
        $queue->assertJsonPath('data.0.uuid', $event->uuid);
    }
}
