<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EVT-011 — review concurrency protection.
 *
 * Two reviewers can open the same REVIEW-queue event before either submits.
 * Without a check, both submissions would each append a decision — the
 * second reviewer never knowing the event had already been resolved by
 * someone else. `expected_decision_uuid` is an optimistic-concurrency
 * token (the `current_decision.uuid` the reviewer's client last fetched);
 * a stale token is rejected with 409 rather than silently appended.
 */
class EventReviewConcurrencyTest extends TestCase
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

    public function test_review_succeeds_when_expected_decision_matches_the_current_one(): void
    {
        $user = $this->userWith(['event.review']);
        $event = AccessEvent::factory()->create();
        $current = AccessDecision::factory()->for($event, 'event')->create(['decision' => 'REVIEW']);

        $res = $this->actingAs($user, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'ALLOW',
            'reason_code' => 'OFFICER_OVERRIDE',
            'expected_decision_uuid' => $current->uuid,
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseCount('access_decisions', 2);
    }

    public function test_the_second_of_two_concurrent_reviewers_is_rejected_with_409(): void
    {
        $officerA = $this->userWith(['event.review']);
        $officerB = $this->userWith(['event.review']);
        $event = AccessEvent::factory()->create();
        $seenByBoth = AccessDecision::factory()->for($event, 'event')->create(['decision' => 'REVIEW']);

        // Officer A submits first — succeeds, event moves on.
        $this->actingAs($officerA, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'ALLOW',
            'reason_code' => 'OFFICER_OVERRIDE',
            'expected_decision_uuid' => $seenByBoth->uuid,
        ])->assertStatus(201);

        // Officer B still has the stale token from before A's review landed.
        $resB = $this->actingAs($officerB, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'DENY',
            'reason_code' => 'IDENTITY_MISMATCH',
            'expected_decision_uuid' => $seenByBoth->uuid,
        ]);

        $resB->assertStatus(409);
        $resB->assertJsonPath('error.code', 'EVENT_REVIEW_CONFLICT');

        // Only A's review was recorded — B's rejected attempt created nothing.
        $this->assertDatabaseCount('access_decisions', 2);
        $this->assertDatabaseHas('access_decisions', [
            'event_id' => $event->id,
            'decision' => 'ALLOW',
            'decided_by_user_id' => $officerA->id,
        ]);
        $this->assertDatabaseMissing('access_decisions', [
            'event_id' => $event->id,
            'decided_by_user_id' => $officerB->id,
        ]);
    }

    public function test_a_stale_conflict_does_not_touch_the_now_current_decision(): void
    {
        $user = $this->userWith(['event.review']);
        $event = AccessEvent::factory()->create();
        $stale = AccessDecision::factory()->for($event, 'event')->create(['decision' => 'REVIEW']);
        $current = AccessDecision::factory()->for($event, 'event')->create(['decision' => 'ALLOW']);

        $this->actingAs($user, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'DENY',
            'reason_code' => 'IDENTITY_MISMATCH',
            'expected_decision_uuid' => $stale->uuid,
        ])->assertStatus(409);

        $current->refresh();
        $this->assertSame('ALLOW', $current->decision->value);
        $this->assertDatabaseCount('access_decisions', 2);
    }

    public function test_review_without_expected_decision_uuid_proceeds_unconditionally(): void
    {
        $user = $this->userWith(['event.review']);
        $event = AccessEvent::factory()->create();
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'REVIEW']);
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'ALLOW']); // already resolved, token not supplied

        $res = $this->actingAs($user, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'DENY',
            'reason_code' => 'IDENTITY_MISMATCH',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseCount('access_decisions', 3);
    }

    public function test_expected_decision_uuid_must_reference_a_real_decision(): void
    {
        $user = $this->userWith(['event.review']);
        $event = AccessEvent::factory()->create();

        $this->actingAs($user, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'ALLOW',
            'reason_code' => 'OFFICER_OVERRIDE',
            'expected_decision_uuid' => 'not-a-real-uuid',
        ])->assertStatus(422);
    }

    public function test_conflict_response_never_reveals_who_the_other_reviewer_was(): void
    {
        $officerA = $this->userWith(['event.review']);
        $officerB = $this->userWith(['event.review']);
        $event = AccessEvent::factory()->create();
        $stale = AccessDecision::factory()->for($event, 'event')->create(['decision' => 'REVIEW']);

        $this->actingAs($officerA, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'ALLOW',
            'reason_code' => 'OFFICER_OVERRIDE',
            'expected_decision_uuid' => $stale->uuid,
        ])->assertStatus(201);

        $resB = $this->actingAs($officerB, 'web')->postJson("/api/v1/events/{$event->uuid}/review", [
            'decision' => 'DENY',
            'reason_code' => 'IDENTITY_MISMATCH',
            'expected_decision_uuid' => $stale->uuid,
        ]);

        $resB->assertStatus(409);
        $body = $resB->json();
        $this->assertArrayNotHasKey('data', $body);
        $this->assertSame('EVENT_REVIEW_CONFLICT', $body['error']['code']);
    }
}
