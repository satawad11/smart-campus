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
 * EVT-009 — review queue (ACCEPTANCE_CRITERIA.md "Review queue works").
 *
 * The queue is GET /events?decision=REVIEW (docs/02-api-contract.md §12
 * defines no separate endpoint): it always reflects each event's *latest*
 * decision, so items leave the queue the instant a review resolves them,
 * without ever rewriting the original system decision.
 */
class ReviewQueueTest extends TestCase
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

    public function test_queue_contains_only_events_currently_awaiting_review(): void
    {
        $user = $this->userWith(['event.view']);

        $pending = AccessEvent::factory()->create();
        AccessDecision::factory()->for($pending, 'event')->create(['decision' => 'REVIEW']);

        $allowed = AccessEvent::factory()->create();
        AccessDecision::factory()->for($allowed, 'event')->create(['decision' => 'ALLOW']);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/events?decision=REVIEW');

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.uuid', $pending->uuid);
    }

    public function test_an_event_leaves_the_queue_once_a_review_resolves_it(): void
    {
        $user = $this->userWith(['event.view']);

        $event = AccessEvent::factory()->create();
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'REVIEW']);

        $before = $this->actingAs($user, 'web')->getJson('/api/v1/events?decision=REVIEW');
        $before->assertJsonCount(1, 'data');

        // Officer review appends a new decision — never overwrites the original.
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'ALLOW', 'decision_source' => 'OFFICER']);

        $after = $this->actingAs($user, 'web')->getJson('/api/v1/events?decision=REVIEW');
        $after->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('access_decisions', 2);
    }

    public function test_an_event_can_re_enter_the_queue_if_a_later_review_escalates_it_back(): void
    {
        $user = $this->userWith(['event.view']);

        $event = AccessEvent::factory()->create();
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'REVIEW']);
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'ALLOW']);
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'REVIEW']); // escalated again

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/events?decision=REVIEW');

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('access_decisions', 3);
    }

    public function test_queue_defaults_to_oldest_first_so_it_is_worked_in_order(): void
    {
        $user = $this->userWith(['event.view']);

        $older = AccessEvent::factory()->create(['occurred_at' => now()->subHours(2)]);
        AccessDecision::factory()->for($older, 'event')->create(['decision' => 'REVIEW']);

        $newer = AccessEvent::factory()->create(['occurred_at' => now()->subMinutes(5)]);
        AccessDecision::factory()->for($newer, 'event')->create(['decision' => 'REVIEW']);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/events?decision=REVIEW');

        $res->assertOk();
        $res->assertJsonPath('data.0.uuid', $older->uuid);
        $res->assertJsonPath('data.1.uuid', $newer->uuid);
    }

    public function test_general_history_browsing_still_defaults_to_newest_first(): void
    {
        $user = $this->userWith(['event.view']);

        $older = AccessEvent::factory()->create(['occurred_at' => now()->subHours(2)]);
        $newer = AccessEvent::factory()->create(['occurred_at' => now()->subMinutes(5)]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/events');

        $res->assertOk();
        $res->assertJsonPath('data.0.uuid', $newer->uuid);
        $res->assertJsonPath('data.1.uuid', $older->uuid);
    }

    public function test_explicit_order_overrides_the_queue_default(): void
    {
        $user = $this->userWith(['event.view']);

        $older = AccessEvent::factory()->create(['occurred_at' => now()->subHours(2)]);
        AccessDecision::factory()->for($older, 'event')->create(['decision' => 'REVIEW']);

        $newer = AccessEvent::factory()->create(['occurred_at' => now()->subMinutes(5)]);
        AccessDecision::factory()->for($newer, 'event')->create(['decision' => 'REVIEW']);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/events?decision=REVIEW&order=desc');

        $res->assertOk();
        $res->assertJsonPath('data.0.uuid', $newer->uuid);
        $res->assertJsonPath('data.1.uuid', $older->uuid);
    }

    public function test_an_event_with_no_decision_at_all_never_appears_in_the_review_queue(): void
    {
        // Should not happen post-EVT-007 (every ingested event gets a
        // decision), but the queue must not silently include un-decided
        // rows if one ever exists (e.g. legacy data, manual seeding).
        $user = $this->userWith(['event.view']);
        AccessEvent::factory()->create();

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/events?decision=REVIEW');

        $res->assertOk();
        $res->assertJsonCount(0, 'data');
    }
}
