<?php

namespace App\Events;

use App\Models\AccessDecision;
use App\Models\AccessEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * EVT-013: fires once per commit for every AccessDecision — system/policy
 * (RecognitionEventIngestionService) or manual (EventReviewService) — so
 * the Live Monitor sees the full activity stream, not just items needing
 * review (that filtering is NotificationService's job, not this one).
 *
 * docs/09 pipeline: "... -> persist decision -> commit -> broadcast ->
 * notify". ShouldBroadcastNow (not queued): this app has no queue worker
 * guarantee, and broadcast is meant to follow commit immediately.
 */
class AccessEventBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly AccessEvent $event,
        public readonly AccessDecision $decision,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("site.{$this->event->site->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'event.decided';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'event' => [
                'uuid' => $this->event->uuid,
                'event_type' => $this->event->event_type,
                'occurred_at' => $this->event->occurred_at?->toISOString(),
                'site_uuid' => $this->event->site->uuid,
                'zone_uuid' => $this->event->zone?->uuid,
                'camera_uuid' => $this->event->camera?->uuid,
                'person_uuid' => $this->event->person?->uuid,
            ],
            'decision' => [
                'uuid' => $this->decision->uuid,
                'decision' => $this->decision->decision->value,
                'reason_code' => $this->decision->reason_code,
                'decision_source' => $this->decision->decision_source->value,
            ],
        ];
    }
}
