<?php

namespace Tests\Feature;

use App\Events\AccessEventBroadcast;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\Camera;
use App\Models\Person;
use App\Models\Zone;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EVT-013 — the broadcastable event itself (the exact substantive,
 * unit-testable surface of "implement Reverb/WebSocket events"). The
 * project's test env runs BROADCAST_CONNECTION=null (phpunit.xml), so
 * calling broadcast() never hits a real socket in any test — asserted
 * indirectly by every other event/review test in this suite still passing
 * with the broadcast() call now wired into both services. What's tested
 * here is what actually goes out: the channel name and payload shape.
 */
class AccessEventBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EventTypeSeeder::class);
    }

    public function test_broadcasts_on_a_private_channel_named_for_the_events_site(): void
    {
        $event = AccessEvent::factory()->create();
        $decision = AccessDecision::factory()->for($event, 'event')->create();

        $broadcast = new AccessEventBroadcast($event, $decision);
        $channels = $broadcast->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-site.{$event->site->uuid}", $channels[0]->name);
    }

    public function test_broadcast_name_is_event_decided(): void
    {
        $event = AccessEvent::factory()->create();
        $decision = AccessDecision::factory()->for($event, 'event')->create();

        $this->assertSame('event.decided', (new AccessEventBroadcast($event, $decision))->broadcastAs());
    }

    public function test_payload_carries_event_and_decision_identifiers_by_uuid_never_numeric_id(): void
    {
        $zone = Zone::factory()->create();
        $camera = Camera::factory()->create(['zone_id' => $zone->id, 'site_id' => $zone->site_id]);
        $person = Person::factory()->create();
        $event = AccessEvent::factory()->create([
            'zone_id' => $zone->id,
            'camera_id' => $camera->id,
            'person_id' => $person->id,
        ]);
        $decision = AccessDecision::factory()->for($event, 'event')->create([
            'decision' => 'DENY',
            'reason_code' => 'STUDENT_EXPELLED',
        ]);

        $payload = (new AccessEventBroadcast($event, $decision))->broadcastWith();

        $this->assertSame($event->uuid, $payload['event']['uuid']);
        $this->assertSame($event->event_type, $payload['event']['event_type']);
        $this->assertSame($event->site->uuid, $payload['event']['site_uuid']);
        $this->assertSame($zone->uuid, $payload['event']['zone_uuid']);
        $this->assertSame($camera->uuid, $payload['event']['camera_uuid']);
        $this->assertSame($person->uuid, $payload['event']['person_uuid']);

        $this->assertSame($decision->uuid, $payload['decision']['uuid']);
        $this->assertSame('DENY', $payload['decision']['decision']);
        $this->assertSame('STUDENT_EXPELLED', $payload['decision']['reason_code']);

        foreach (['event', 'decision'] as $key) {
            $this->assertArrayNotHasKey('id', $payload[$key]);
        }
    }

    public function test_payload_handles_an_unmatched_person_and_zoneless_camera_gracefully(): void
    {
        $event = AccessEvent::factory()->create(['zone_id' => null, 'camera_id' => null, 'person_id' => null]);
        $decision = AccessDecision::factory()->for($event, 'event')->create();

        $payload = (new AccessEventBroadcast($event, $decision))->broadcastWith();

        $this->assertNull($payload['event']['zone_uuid']);
        $this->assertNull($payload['event']['camera_uuid']);
        $this->assertNull($payload['event']['person_uuid']);
    }
}
