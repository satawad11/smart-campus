<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Zone;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EVT-015 — missed-event recovery (ACCEPTANCE_CRITERIA.md "Reconnect can
 * recover missed events").
 *
 * No separate recovery endpoint exists, and none is needed: the realtime
 * channel a Live Monitor client subscribes to is site-scoped
 * (site.{siteUuid}, EVT-013/EVT-014), and GET /events already accepts
 * exactly the two filters — site_uuid + from — a reconnecting client needs
 * to ask "what happened on my channel while I was disconnected?" A client
 * remembers the occurred_at of the last event it successfully processed
 * before losing its WebSocket connection and, on reconnect, replays
 * GET /events?site_uuid=<mine>&from=<lastSeen>&order=asc to backfill the
 * gap before resuming live broadcasts. `from` is inclusive (>=), so the
 * client must dedupe by event uuid — proven below.
 */
class EventMissedEventRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(EventTypeSeeder::class);

        // occurred_at is timestamptz(0): PostgreSQL rounds to the second, so an
        // event created at now()-9min and a `from` computed a few ms later
        // (or from the unrounded in-memory model) could fall on either side of
        // each other; the tests failed intermittently on PostgreSQL only.
        // Freezing on a whole second makes stored and computed times equal.
        $this->freezeSecond();
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

    public function test_a_reconnecting_client_recovers_exactly_the_events_it_missed(): void
    {
        $user = $this->userWith(['event.view']);
        $site = Site::factory()->create();

        $lastSeen = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => now()->subMinutes(10)]);
        AccessDecision::factory()->for($lastSeen, 'event')->create();

        // Disconnected here — these three happened while the socket was down.
        $missed = collect(range(1, 3))->map(fn ($i) => tap(
            AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => now()->subMinutes(10 - $i)]),
            fn ($event) => AccessDecision::factory()->for($event, 'event')->create(),
        ));

        // A different site's events must never leak into recovery.
        $otherSiteEvent = AccessEvent::factory()->create(['occurred_at' => now()->subMinutes(5)]);
        AccessDecision::factory()->for($otherSiteEvent, 'event')->create();

        $res = $this->actingAs($user, 'web')->getJson(
            "/api/v1/events?site_uuid={$site->uuid}&from={$lastSeen->occurred_at->toISOString()}&order=asc"
        );

        $res->assertOk();
        $uuids = collect($res->json('data'))->pluck('uuid')->all();

        // Inclusive `from`: the last-seen event reappears — the client
        // dedupes it by uuid, since it already has it.
        $this->assertContains($lastSeen->uuid, $uuids);
        foreach ($missed as $event) {
            $this->assertContains($event->uuid, $uuids);
        }
        $this->assertNotContains($otherSiteEvent->uuid, $uuids);
        $this->assertCount(4, $uuids); // last-seen + 3 missed, nothing else
    }

    public function test_recovered_events_arrive_in_chronological_order_for_replay(): void
    {
        $user = $this->userWith(['event.view']);
        $site = Site::factory()->create();

        $third = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => now()->subMinutes(1)]);
        $first = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => now()->subMinutes(9)]);
        $second = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => now()->subMinutes(5)]);
        foreach ([$third, $first, $second] as $event) {
            AccessDecision::factory()->for($event, 'event')->create();
        }

        $res = $this->actingAs($user, 'web')->getJson(
            "/api/v1/events?site_uuid={$site->uuid}&from=".now()->subMinutes(9)->toISOString().'&order=asc'
        );

        $res->assertOk();
        $uuids = collect($res->json('data'))->pluck('uuid')->all();
        $this->assertSame([$first->uuid, $second->uuid, $third->uuid], $uuids);
    }

    public function test_recovery_can_be_narrowed_to_a_single_zone_or_camera(): void
    {
        $user = $this->userWith(['event.view']);
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();

        $inZone = AccessEvent::factory()->create(['site_id' => $site->id, 'zone_id' => $zone->id, 'occurred_at' => now()->subMinutes(5)]);
        $elsewhereInSite = AccessEvent::factory()->create(['site_id' => $site->id, 'zone_id' => null, 'occurred_at' => now()->subMinutes(5)]);
        foreach ([$inZone, $elsewhereInSite] as $event) {
            AccessDecision::factory()->for($event, 'event')->create();
        }

        $res = $this->actingAs($user, 'web')->getJson(
            "/api/v1/events?zone_uuid={$zone->uuid}&from=".now()->subMinutes(9)->toISOString()
        );

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.uuid', $inZone->uuid);
    }

    public function test_recovering_after_a_long_disconnect_still_returns_every_missed_event_across_pages(): void
    {
        $user = $this->userWith(['event.view']);
        $site = Site::factory()->create();

        $since = now()->subHour();
        collect(range(1, 25))->each(function ($i) use ($site) {
            $event = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => now()->subMinutes(50 - $i)]);
            AccessDecision::factory()->for($event, 'event')->create();
        });

        $res = $this->actingAs($user, 'web')->getJson(
            "/api/v1/events?site_uuid={$site->uuid}&from={$since->toISOString()}&order=asc&per_page=100"
        );

        $res->assertOk();
        $res->assertJsonCount(25, 'data');
        $this->assertSame(25, $res->json('meta.pagination.total'));
    }

    public function test_no_gap_means_recovery_returns_nothing_new(): void
    {
        $user = $this->userWith(['event.view']);
        $site = Site::factory()->create();
        $lastSeen = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => now()]);
        AccessDecision::factory()->for($lastSeen, 'event')->create();

        $res = $this->actingAs($user, 'web')->getJson(
            "/api/v1/events?site_uuid={$site->uuid}&from=".now()->addSecond()->toISOString()
        );

        $res->assertOk();
        $res->assertJsonCount(0, 'data');
    }
}
